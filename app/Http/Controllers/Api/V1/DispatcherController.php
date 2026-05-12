<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Dispatcher;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class DispatcherController extends Controller
{
    public function index(Request $request)
    {
        $query = Dispatcher::with(['user', 'vehicle']);

        if ($request->has('is_available')) {
            $query->where('is_available', $request->boolean('is_available'));
        }

        $dispatchers = $query->orderBy('created_at', 'desc')->paginate(20);

        return $this->success($dispatchers);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'license_number' => 'required|string|max:50',
            'license_expiry' => 'required|date',
            'vehicle_id' => 'nullable|exists:vehicles,id',
        ]);

        $role = \App\Models\Role::where('slug', 'dispatcher')->firstOrFail();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make('dispatcher123'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $dispatcherData = [
            'user_id' => $user->id,
            'license_number' => $validated['license_number'],
            'license_expiry' => $validated['license_expiry'],
            'vehicle_id' => $validated['vehicle_id'] ?? null,
        ];

        $dispatcher = Dispatcher::create($dispatcherData);
        $dispatcher->load(['user', 'vehicle']);

        return $this->success($dispatcher, 'Dispatcher created successfully', 201);
    }

    public function show(Dispatcher $dispatcher)
    {
        $dispatcher->load(['user', 'vehicle', 'shipments.customer', 'pickupDeliveries', 'fulfillmentRequests.partnerCustomer.customer', 'fulfillmentRequests.partnerProduct']);

        return $this->success($dispatcher);
    }

    public function update(Request $request, Dispatcher $dispatcher)
    {
        $validated = $request->validate([
            'license_number' => 'sometimes|string|max:50',
            'license_expiry' => 'sometimes|date',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'is_available' => 'sometimes|boolean',
        ]);

        $dispatcher->update($validated);

        return $this->success($dispatcher, 'Dispatcher updated successfully');
    }

    public function destroy(Dispatcher $dispatcher)
    {
        $dispatcher->user->delete();
        $dispatcher->delete();

        return $this->success(null, 'Dispatcher deleted successfully');
    }

    public function assignVehicle(Request $request, Dispatcher $dispatcher)
    {
        $validated = $request->validate([
            'vehicle_id' => 'required|exists:vehicles,id',
        ]);

        $dispatcher->update(['vehicle_id' => $validated['vehicle_id']]);

        return $this->success($dispatcher, 'Vehicle assigned successfully');
    }

    public function deliveries(Dispatcher $dispatcher)
    {
        $shipments = $dispatcher->shipments()
            ->with('customer')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return $this->success($shipments);
    }

    public function available()
    {
        $dispatchers = Dispatcher::with(['user', 'vehicle'])
            ->where('is_available', true)
            ->get();

        return $this->success($dispatchers);
    }
}
