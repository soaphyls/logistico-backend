<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    public function index(Request $request)
    {
        $query = Warehouse::with('manager');

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $warehouses = $query->orderBy('name', 'asc')->paginate(20);

        return $this->success($warehouses);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|unique:warehouses,code|max:20',
            'address' => 'required',
            'city' => 'required|string|max:100',
            'state' => 'required|string|max:100',
            'manager_id' => 'nullable|exists:users,id',
            'capacity' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        $warehouse = Warehouse::create($validated);

        return $this->success($warehouse, 'Warehouse created successfully', 201);
    }

    public function show(Warehouse $warehouse)
    {
        $warehouse->load(['manager', 'shipments', 'inventory']);

        $occupancyCount = $warehouse->shipments()->where('status', 'at_warehouse')->count();

        $warehouseData = $warehouse->toArray();
        $warehouseData['occupancy_count'] = $occupancyCount;

        return $this->success($warehouseData);
    }

    public function update(Request $request, Warehouse $warehouse)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => 'sometimes|string|unique:warehouses,code,' . $warehouse->id . '|max:20',
            'address' => 'sometimes',
            'city' => 'sometimes|string|max:100',
            'state' => 'sometimes|string|max:100',
            'manager_id' => 'nullable|exists:users,id',
            'capacity' => 'nullable|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        $warehouse->update($validated);

        return $this->success($warehouse, 'Warehouse updated successfully');
    }

    public function destroy(Warehouse $warehouse)
    {
        if ($warehouse->shipments()->where('status', 'at_warehouse')->count() > 0) {
            return $this->error('Cannot delete warehouse with active shipments', 400);
        }

        $warehouse->delete();

        return $this->success(null, 'Warehouse deleted successfully');
    }

    public function shipments(Warehouse $warehouse)
    {
        $shipments = $warehouse->shipments()
            ->with('customer')
            ->where('status', 'at_warehouse')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return $this->success($shipments);
    }

    public function inventory(Warehouse $warehouse)
    {
        $inventory = $warehouse->inventory()
            ->orderBy('item_name', 'asc')
            ->paginate(20);

        return $this->success($inventory);
    }
}
