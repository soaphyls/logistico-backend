<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Warehouse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $query = Inventory::with('warehouse');

        if ($request->has('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        if ($request->has('low_stock')) {
            $query->whereRaw('quantity <= reorder_level');
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('item_name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        $inventory = $query->orderBy('item_name', 'asc')->paginate(20);

        return $this->success($inventory);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'item_name' => 'required|string|max:255',
            'sku' => 'nullable|string|max:50|unique:inventory,sku',
            'category' => 'required|string|max:100',
            'quantity' => 'required|integer|min:0',
            'reorder_level' => 'integer|min:0',
            'unit' => 'required|string|max:20',
            'unit_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $inventory = Inventory::create($validated);

        return $this->success($inventory, 'Inventory item created successfully', 201);
    }

    public function show(Inventory $inventory)
    {
        $inventory->load('warehouse');

        return $this->success($inventory);
    }

    public function update(Request $request, Inventory $inventory)
    {
        $validated = $request->validate([
            'warehouse_id' => 'sometimes|exists:warehouses,id',
            'item_name' => 'sometimes|string|max:255',
            'sku' => 'nullable|string|max:50|unique:inventory,sku,' . $inventory->id,
            'category' => 'sometimes|string|max:100',
            'quantity' => 'sometimes|integer|min:0',
            'reorder_level' => 'integer|min:0',
            'unit' => 'sometimes|string|max:20',
            'unit_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $inventory->update($validated);

        return $this->success($inventory, 'Inventory item updated successfully');
    }

    public function destroy(Inventory $inventory)
    {
        $inventory->delete();

        return $this->success(null, 'Inventory item deleted successfully');
    }

    public function adjust(Request $request, Inventory $inventory)
    {
        $validated = $request->validate([
            'adjustment' => 'required|integer',
            'notes' => 'nullable|string',
        ]);

        $newQuantity = $inventory->quantity + $validated['adjustment'];
        
        if ($newQuantity < 0) {
            return $this->error('Adjustment would result in negative quantity', 400);
        }

        $inventory->update(['quantity' => $newQuantity]);

        return $this->success($inventory, 'Inventory adjusted successfully');
    }

    public function lowStock()
    {
        $lowStockItems = Inventory::with('warehouse')
            ->whereRaw('quantity <= reorder_level')
            ->orderBy('quantity', 'asc')
            ->get();

        return $this->success($lowStockItems);
    }
}
