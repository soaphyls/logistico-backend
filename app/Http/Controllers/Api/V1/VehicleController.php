<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    public function index(Request $request)
    {
        $query = Vehicle::query();

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        $vehicles = $query->with('dispatcher.user')->orderBy('created_at', 'desc')->paginate(20);

        return $this->success($vehicles);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'plate_number' => 'required|string|unique:vehicles,plate_number|max:20',
            'make' => 'required|string|max:50',
            'model' => 'required|string|max:50',
            'year' => 'required|integer|min:1900|max:' . (date('Y') + 1),
            'type' => 'required|in:bike,van,truck,pickup',
            'status' => 'sometimes|in:available,on_trip,maintenance,inactive',
            'last_maintenance_date' => 'nullable|date',
            'next_maintenance_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $vehicle = Vehicle::create($validated);

        return $this->success($vehicle, 'Vehicle created successfully', 201);
    }

    public function show(Vehicle $vehicle)
    {
        $vehicle->load(['dispatcher.user', 'shipments', 'expenses']);

        return $this->success($vehicle);
    }

    public function update(Request $request, Vehicle $vehicle)
    {
        $validated = $request->validate([
            'plate_number' => 'sometimes|string|unique:vehicles,plate_number,' . $vehicle->id . '|max:20',
            'make' => 'sometimes|string|max:50',
            'model' => 'sometimes|string|max:50',
            'year' => 'sometimes|integer|min:1900|max:' . (date('Y') + 1),
            'type' => 'sometimes|in:bike,van,truck,pickup',
            'status' => 'sometimes|in:available,on_trip,maintenance,inactive',
            'last_maintenance_date' => 'nullable|date',
            'next_maintenance_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $vehicle->update($validated);

        return $this->success($vehicle, 'Vehicle updated successfully');
    }

    public function destroy(Vehicle $vehicle)
    {
        if ($vehicle->dispatcher) {
            return $this->error('Cannot delete vehicle assigned to a dispatcher', 400);
        }

        if ($vehicle->shipments()->count() > 0) {
            return $this->error('Cannot delete vehicle with assigned shipments', 400);
        }

        $vehicle->delete();

        return $this->success(null, 'Vehicle deleted successfully');
    }

    public function maintenance(Request $request, Vehicle $vehicle)
    {
        $validated = $request->validate([
            'last_maintenance_date' => 'nullable|date',
            'next_maintenance_date' => 'nullable|date',
        ]);

        $vehicle->update($validated);

        return $this->success($vehicle, 'Maintenance dates updated');
    }

    public function dueMaintenance()
    {
        $vehicles = Vehicle::dueForMaintenance()->with('dispatcher.user')->get();

        return $this->success($vehicles);
    }
}
