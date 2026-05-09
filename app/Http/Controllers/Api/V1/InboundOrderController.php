<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InboundOrder;
use App\Models\InventoryStock;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InboundOrderController extends Controller
{
    protected $inventoryService;

    public function __construct(InventoryService $inventoryService)
    {
        $this->inventoryService = $inventoryService;
    }

    public function index(Request $request)
    {
        $query = InboundOrder::with(['partnerCustomer', 'warehouse']);

        if ($request->has('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        if ($request->has('partner_customer_id')) {
            $query->where('partner_customer_id', $request->partner_customer_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $orders = $query->orderBy('id', 'desc')->paginate(20);

        return $this->success($orders);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'partner_customer_id' => 'required|exists:partner_customers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'partner_product_id' => 'required|exists:partner_products,id',
            'expected_quantity' => 'required|integer|min:1',
            'carrier' => 'nullable',
            'vehicle_number' => 'nullable',
            'expected_arrival_date' => 'nullable|date',
            'notes' => 'nullable',
        ]);

        $product = \App\Models\PartnerProduct::find($validated['partner_product_id']);
        $validated['product_name'] = $product->name;
        $validated['sku'] = $product->sku;
        $validated['reference_number'] = InboundOrder::generateReferenceNumber();

        $order = InboundOrder::create($validated);

        return $this->success($order, 'Inbound order created');
    }

    public function show(InboundOrder $inboundOrder)
    {
        $inboundOrder->load(['partnerCustomer', 'warehouse', 'partnerProduct', 'receivedByUser']);

        return $this->success($inboundOrder);
    }

    public function receive(Request $request, InboundOrder $inboundOrder)
    {
        $validated = $request->validate([
            'received_quantity' => 'required|integer|min:1',
            'notes' => 'nullable',
        ]);

        if ($inboundOrder->status === InboundOrder::STATUS_RECEIVED) {
            return $this->error('Order already fully received', 400);
        }

        $maxReceive = $inboundOrder->expected_quantity - $inboundOrder->received_quantity;
        $actualReceive = min($validated['received_quantity'], $maxReceive);

        $stock = InventoryStock::firstOrCreate(
            [
                'warehouse_id' => $inboundOrder->warehouse_id,
                'product_id' => $inboundOrder->partner_product_id,
            ],
            [
                'product_name' => $inboundOrder->product_name,
                'sku' => $inboundOrder->sku,
                'quantity_on_hand' => 0,
                'quantity_allocated' => 0,
            ]
        );

        $this->inventoryService->receive(
            $stock->id,
            $actualReceive,
            InboundOrder::class,
            $inboundOrder->id,
            $validated['notes'] ?? 'Inbound receiving'
        );

        $inboundOrder->received_quantity += $actualReceive;
        
        if ($inboundOrder->received_quantity >= $inboundOrder->expected_quantity) {
            $inboundOrder->status = InboundOrder::STATUS_RECEIVED;
        } elseif ($inboundOrder->received_quantity > 0) {
            $inboundOrder->status = InboundOrder::STATUS_PARTIALLY_RECEIVED;
        }
        
        $inboundOrder->received_at = now();
        $inboundOrder->received_by = Auth::id();
        $inboundOrder->save();

        return $this->success($inboundOrder, "Received {$actualReceive} units");
    }

    public function cancel(InboundOrder $inboundOrder)
    {
        if ($inboundOrder->status !== InboundOrder::STATUS_PENDING) {
            return $this->error('Only pending orders can be cancelled', 400);
        }

        $inboundOrder->update(['status' => InboundOrder::STATUS_CANCELLED]);

        return $this->success($inboundOrder, 'Order cancelled');
    }
}