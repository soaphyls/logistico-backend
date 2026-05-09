<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryTransfer;
use App\Models\InventoryStock;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InventoryTransferController extends Controller
{
    protected $inventoryService;

    public function __construct(InventoryService $inventoryService)
    {
        $this->inventoryService = $inventoryService;
    }

    public function index(Request $request)
    {
        $query = InventoryTransfer::with(['sourceWarehouse', 'destinationWarehouse']);

        if ($request->has('warehouse_id')) {
            $query->where(function ($q) use ($request) {
                $q->where('source_warehouse_id', $request->warehouse_id)
                  ->orWhere('destination_warehouse_id', $request->warehouse_id);
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $transfers = $query->orderBy('id', 'desc')->paginate(20);

        return $this->success($transfers);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'source_warehouse_id' => 'required|exists:warehouses,id',
            'destination_warehouse_id' => 'required|exists:warehouses,id|different:source_warehouse_id',
            'product_id' => 'required|exists:partner_products,id',
            'quantity' => 'required|integer|min:1',
            'notes' => 'nullable',
        ]);

        $product = \App\Models\PartnerProduct::find($validated['product_id']);
        $validated['product_name'] = $product->name;
        $validated['sku'] = $product->sku;
        $validated['reference_number'] = InventoryTransfer::generateReferenceNumber();
        $validated['requested_by'] = Auth::id();
        $validated['status'] = InventoryTransfer::STATUS_DRAFT;

        $transfer = InventoryTransfer::create($validated);

        return $this->success($transfer, 'Transfer created');
    }

    public function show(InventoryTransfer $transfer)
    {
        $transfer->load(['sourceWarehouse', 'destinationWarehouse', 'partnerProduct', 'requestedByUser', 'approvedByUser']);

        return $this->success($transfer);
    }

    public function submit(InventoryTransfer $transfer)
    {
        if (!$transfer->canApprove()) {
            return $this->error('Transfer cannot be submitted', 400);
        }

        $sourceStock = InventoryStock::where('warehouse_id', $transfer->source_warehouse_id)
            ->where('product_id', $transfer->product_id)
            ->first();

        if (!$sourceStock || $transfer->quantity > $sourceStock->quantity_available) {
            return $this->error('Insufficient available stock', 400);
        }

        $this->inventoryService->allocate(
            $sourceStock->id,
            $transfer->quantity,
            InventoryTransfer::class,
            $transfer->id
        );

        $transfer->update(['status' => InventoryTransfer::STATUS_PENDING_APPROVAL]);

        return $this->success($transfer, 'Transfer submitted for approval');
    }

    public function approve(Request $request, InventoryTransfer $transfer)
    {
        $user = Auth::user();
        
        if ($transfer->status !== InventoryTransfer::STATUS_PENDING_APPROVAL) {
            return $this->error('Transfer is not pending approval', 400);
        }

        $transfer->update([
            'status' => InventoryTransfer::STATUS_APPROVED,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        return $this->success($transfer, 'Transfer approved');
    }

    public function reject(Request $request, InventoryTransfer $transfer)
    {
        $validated = $request->validate([
            'reason' => 'required|string',
        ]);

        if ($transfer->status !== InventoryTransfer::STATUS_PENDING_APPROVAL) {
            return $this->error('Transfer is not pending approval', 400);
        }

        $sourceStock = InventoryStock::where('warehouse_id', $transfer->source_warehouse_id)
            ->where('product_id', $transfer->product_id)
            ->first();

        if ($sourceStock) {
            $this->inventoryService->deallocate(
                $sourceStock->id,
                $transfer->quantity,
                InventoryTransfer::class,
                $transfer->id
            );
        }

        $transfer->update([
            'status' => InventoryTransfer::STATUS_CANCELLED,
            'rejection_reason' => $validated['reason'],
        ]);

        return $this->success($transfer, 'Transfer rejected');
    }

    public function ship(InventoryTransfer $transfer)
    {
        if (!$transfer->canShip()) {
            return $this->error('Transfer cannot be shipped', 400);
        }

        $sourceStock = InventoryStock::where('warehouse_id', $transfer->source_warehouse_id)
            ->where('product_id', $transfer->product_id)
            ->first();

        if ($sourceStock) {
            $this->inventoryService->fulfill(
                $sourceStock->id,
                $transfer->quantity,
                InventoryTransfer::class,
                $transfer->id
            );
        }

        $transfer->update([
            'status' => InventoryTransfer::STATUS_IN_TRANSIT,
            'shipped_at' => now(),
        ]);

        return $this->success($transfer, 'Transfer shipped');
    }

    public function receive(InventoryTransfer $transfer)
    {
        if (!$transfer->canReceive()) {
            return $this->error('Transfer cannot be received', 400);
        }

        $this->inventoryService->transfer($transfer->id);

        $transfer->update([
            'status' => InventoryTransfer::STATUS_RECEIVED,
            'received_at' => now(),
        ]);

        return $this->success($transfer, 'Transfer received');
    }
}