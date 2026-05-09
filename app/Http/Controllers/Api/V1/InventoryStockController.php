<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryStock;
use App\Models\InventoryTransaction;
use App\Models\InventoryTransfer;
use App\Models\InboundOrder;
use App\Models\CycleCount;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InventoryStockController extends Controller
{
    protected $inventoryService;

    public function __construct(InventoryService $inventoryService)
    {
        $this->inventoryService = $inventoryService;
    }

    public function index(Request $request)
    {
        $query = InventoryStock::with('warehouse');

        if ($request->has('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('product_name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        if ($request->has('low_stock')) {
            $query->whereRaw('quantity_on_hand <= reorder_level');
        }

        $inventory = $query->orderBy('product_name', 'asc')->paginate(20);

        return $this->success($inventory);
    }

    public function show(InventoryStock $inventoryStock)
    {
        $inventoryStock->load('warehouse', 'partnerProduct');
        $transactions = InventoryTransaction::where('inventory_stock_id', $inventoryStock->id)
            ->orderBy('id', 'desc')
            ->limit(50)
            ->get();

        return $this->success([
            'stock' => $inventoryStock,
            'recent_transactions' => $transactions,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'product_name' => 'required',
            'sku' => 'nullable',
            'quantity_on_hand' => 'integer|min:0',
            'bin_location' => 'nullable',
            'reorder_level' => 'integer|min:0',
            'unit' => 'nullable',
            'unit_cost' => 'nullable|numeric',
            'notes' => 'nullable',
        ]);

        $stock = InventoryStock::create($validated);

        return $this->success($stock, 'Stock created successfully');
    }

    public function update(Request $request, InventoryStock $inventoryStock)
    {
        $validated = $request->validate([
            'product_name' => 'sometimes',
            'sku' => 'sometimes',
            'bin_location' => 'nullable',
            'reorder_level' => 'integer|min:0',
            'unit' => 'nullable',
            'unit_cost' => 'nullable|numeric',
            'notes' => 'nullable',
        ]);

        $inventoryStock->update($validated);

        return $this->success($inventoryStock, 'Stock updated successfully');
    }

    public function adjust(Request $request, InventoryStock $inventoryStock)
    {
        $validated = $request->validate([
            'new_quantity' => 'required|integer|min:0',
            'reason' => 'required|string',
        ]);

        $stock = $this->inventoryService->adjust(
            $inventoryStock->id,
            $validated['new_quantity'],
            $validated['reason']
        );

        return $this->success($stock, 'Stock adjusted successfully');
    }

    public function lowStock()
    {
        $stocks = InventoryStock::whereRaw('quantity_on_hand <= reorder_level')
            ->with('warehouse')
            ->orderBy('quantity_on_hand', 'asc')
            ->get();

        return $this->success($stocks);
    }

    public function getTransactions(Request $request)
    {
        $query = InventoryTransaction::with('user');

        if ($request->has('stock_id')) {
            $query->where('inventory_stock_id', $request->stock_id);
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        $transactions = $query->orderBy('id', 'desc')->paginate(50);

        return $this->success($transactions);
    }
}