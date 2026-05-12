<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Dispatcher;
use App\Models\Notification;
use App\Models\PickupDelivery;
use App\Models\Shipment;
use Illuminate\Http\Request;

class PickupDeliveryController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        $role = $user->role?->slug;
        
        $query = PickupDelivery::with(['shipment', 'dispatcher.user', 'createdBy']);

        if ($role === 'dispatcher') {
            $dispatcher = Dispatcher::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'license_number' => 'DL-' . strtoupper(uniqid()),
                    'license_expiry' => now()->addYear(),
                    'is_available' => true,
                ]
            );
            $query->where('dispatcher_id', $dispatcher->id);
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('dispatcher_id')) {
            $query->where('dispatcher_id', $request->dispatcher_id);
        }

        if ($request->has('date')) {
            $query->whereDate('scheduled_date', $request->date);
        }

        $perPage = min((int) $request->get('per_page', 20), 500);
        $pickupDeliveries = $query->orderBy('scheduled_date', 'desc')->paginate($perPage);

        return $this->success($pickupDeliveries);
    }

    public function store(Request $request)
    {
        $user = auth()->user();
        $role = $user->role?->slug;

        if (in_array($role, ['dispatcher', 'customer_service'])) {
            return $this->error('You do not have permission to create pickup/delivery schedules', 403);
        }

        $validated = $request->validate([
            'shipment_id' => 'required|exists:shipments,id',
            'type' => 'required|in:pickup,delivery',
            'dispatcher_id' => 'nullable|exists:dispatchers,id',
            'scheduled_date' => 'required|date',
            'time_window_start' => 'nullable',
            'time_window_end' => 'nullable',
            'notes' => 'nullable|string',
            // Location Fields
            'pickup_address' => 'nullable|string',
            'pickup_city' => 'nullable|string',
            'pickup_state' => 'nullable|string',
            'pickup_phone' => 'nullable|string',
            'delivery_address' => 'nullable|string',
            'delivery_city' => 'nullable|string',
            'delivery_state' => 'nullable|string',
            'delivery_phone' => 'nullable|string',
            // Timing
            'estimated_arrival' => 'nullable|date',
        ]);

        $validated['created_by'] = auth()->id();
        $validated['status'] = $validated['dispatcher_id'] ? 'assigned' : 'scheduled';

        $pickupDelivery = PickupDelivery::create($validated);

        return $this->success($pickupDelivery, 'Pickup/Delivery scheduled successfully', 201);
    }

    public function show(PickupDelivery $pickupDelivery)
    {
        $user = auth()->user();
        $role = $user->role?->slug;

        if ($role === 'dispatcher') {
            $dispatcher = Dispatcher::where('user_id', $user->id)->first();
            if (!$dispatcher || $pickupDelivery->dispatcher_id !== $dispatcher->id) {
                return $this->error('Access denied', 403);
            }
        }

        $pickupDelivery->load(['shipment', 'dispatcher.user', 'createdBy']);

        return $this->success($pickupDelivery);
    }

    public function update(Request $request, PickupDelivery $pickupDelivery)
    {
        $user = auth()->user();
        $role = $user->role?->slug;

        if (in_array($role, ['dispatcher', 'customer_service'])) {
            return $this->error('You do not have permission to update pickup/delivery schedules', 403);
        }

        $validated = $request->validate([
            'scheduled_date' => 'sometimes|date',
            'time_window_start' => 'nullable',
            'time_window_end' => 'nullable',
            'notes' => 'nullable|string',
        ]);

        $pickupDelivery->update($validated);

        // Notify dispatcher if assigned
        if ($pickupDelivery->dispatcher_id) {
            try {
                $dispatcher = Dispatcher::find($pickupDelivery->dispatcher_id);
                if ($dispatcher) {
                    $shipment = $pickupDelivery->shipment;
                    $botEngine = app(\App\Services\Bot\BotEngine::class);
                    $botEngine->notifyUser(
                        $dispatcher->user_id, 
                        "⏰ <b>Schedule Updated!</b>\n\nThe schedule for your <b>" . strtoupper($pickupDelivery->type) . "</b> task has been modified.\n\n" .
                        "📄 <b>Shipment:</b> " . ($shipment ? $shipment->tracking_number : 'N/A') . "\n" .
                        "⏰ <b>New Schedule:</b> " . ($pickupDelivery->scheduled_date ? \Carbon\Carbon::parse($pickupDelivery->scheduled_date)->format('d M, Y') : 'Not set') . "\n" .
                        "📝 <b>Notes:</b> " . ($pickupDelivery->notes ?? 'No additional notes')
                    );
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("Failed to send bot schedule update notification: " . $e->getMessage());
            }
        }

        return $this->success($pickupDelivery, 'Pickup/Delivery updated successfully');
    }

    public function assign(Request $request, PickupDelivery $pickupDelivery)
    {
        $user = auth()->user();
        $role = $user->role?->slug;

        if (in_array($role, ['dispatcher', 'customer_service'])) {
            return $this->error('You do not have permission to assign dispatchers', 403);
        }

        $validated = $request->validate([
            'dispatcher_id' => 'required|exists:dispatchers,id',
            'scheduled_date' => 'nullable|date',
            'expected_delivery_date' => 'nullable|date',
            'time_window' => 'nullable|string',
        ]);

        $dispatcher = Dispatcher::findOrFail($validated['dispatcher_id']);

        $pickupDelivery->update([
            'dispatcher_id' => $validated['dispatcher_id'],
            'scheduled_date' => $validated['scheduled_date'] ?? $pickupDelivery->scheduled_date,
            'time_window' => $validated['time_window'] ?? $pickupDelivery->time_window,
            'status' => 'assigned',
        ]);

        // Also update the delivery for the same shipment (if this is a pickup)
        $shipment = $pickupDelivery->shipment;
        if ($shipment) {
            $updateData = [
                'dispatcher_id' => $validated['dispatcher_id'],
            ];
            
            if (isset($validated['expected_delivery_date'])) {
                $updateData['scheduled_delivery_date'] = $validated['expected_delivery_date'];
            }
            
            // Only update status to picked_up if this is a pickup
            if ($pickupDelivery->type === 'pickup' && $shipment->status === 'pending') {
                $updateData['status'] = 'picked_up';
            }
            
            $shipment->update($updateData);

            if ($pickupDelivery->type === 'pickup') {
                // Find and update the delivery record for the same shipment
                PickupDelivery::where('shipment_id', $shipment->id)
                    ->where('type', 'delivery')
                    ->update([
                        'dispatcher_id' => $validated['dispatcher_id'],
                        'scheduled_date' => $validated['expected_delivery_date'] ?? $shipment->scheduled_delivery_date,
                    ]);
            }
        }

        Notification::create([
            'user_id' => $dispatcher->user_id,
            'title' => 'Pickup/Delivery Assigned',
            'message' => "You have been assigned a {$pickupDelivery->type} for shipment #{$shipment->tracking_number}",
            'type' => 'delivery',
            'related_to_type' => PickupDelivery::class,
            'related_to_id' => $pickupDelivery->id,
        ]);

        // Send Bot Notification
        try {
            $botEngine = app(\App\Services\Bot\BotEngine::class);
            $botEngine->notifyUser(
                $dispatcher->user_id, 
                "📦 <b>New Job Assigned!</b>\n\nYou have been assigned a <b>" . strtoupper($pickupDelivery->type) . "</b> task.\n\n" .
                "📄 <b>Shipment:</b> {$shipment->tracking_number}\n" .
                "📍 <b>Address:</b> " . ($pickupDelivery->type === 'pickup' ? $shipment->sender_address : $shipment->receiver_address) . "\n" .
                "⏰ <b>Scheduled:</b> " . ($pickupDelivery->scheduled_date ? \Carbon\Carbon::parse($pickupDelivery->scheduled_date)->format('d M, Y') : 'Not set')
            );
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to send bot assignment notification: " . $e->getMessage());
        }

        return $this->success([
            'pickup_delivery' => $pickupDelivery,
            'shipment' => $shipment ?? null,
        ], 'Dispatcher assigned successfully!');
    }

    public function updateStatus(Request $request, PickupDelivery $pickupDelivery)
    {
        $user = auth()->user();
        $role = $user->role?->slug;

        if ($role === 'dispatcher') {
            $dispatcher = Dispatcher::where('user_id', $user->id)->first();
            if (!$dispatcher || $pickupDelivery->dispatcher_id !== $dispatcher->id) {
                return $this->error('Access denied', 403);
            }
        }

        $validated = $request->validate([
            'status' => 'required|in:scheduled,assigned,picked_up,in_transit,in_progress,completed,failed,pending',
            'notes' => 'nullable|string',
            // Completion Details
            'recipient_name' => 'nullable|string',
            'recipient_signature' => 'nullable|string',
            'proof_photo' => 'nullable|string',
            // Failure Details
            'failure_reason' => 'nullable|string',
            'failure_notes' => 'nullable|string',
            // Additional
            'completion_notes' => 'nullable|string',
            'customer_notified' => 'nullable|boolean',
        ]);

        $pickupDelivery->update($validated);

        if ($validated['status'] === 'in_progress') {
            $pickupDelivery->update(['actual_start_time' => now()]);
        }

        if ($validated['status'] === 'completed') {
            $pickupDelivery->update([
                'actual_date' => now(),
                'actual_completion_time' => now(),
            ]);
            
            $shipment = $pickupDelivery->shipment;
            
            if ($pickupDelivery->type === 'pickup') {
                $shipment->update([
                    'status' => 'picked_up', 
                    'actual_pickup_date' => now()
                ]);
            } else {
                $shipment->update([
                    'status' => 'delivered', 
                    'actual_delivery_date' => now(),
                    'recipient_name' => $validated['recipient_name'] ?? null,
                    'recipient_signature' => $validated['recipient_signature'] ?? null,
                ]);
                
                if ($shipment->dispatcher) {
                    $shipment->dispatcher->increment('total_deliveries');
                    $shipment->dispatcher->increment('successful_deliveries');
                }
            }
        }

        if ($validated['status'] === 'failed') {
            $pickupDelivery->increment('attempt_number');
            $pickupDelivery->shipment->update(['status' => 'failed']);
        }

        return $this->success($pickupDelivery, 'Status updated successfully');
    }

    public function dispatcherToday(Dispatcher $dispatcher)
    {
        $user = auth()->user();
        $role = $user->role?->slug;

        if ($role === 'dispatcher') {
            $currentDispatcher = Dispatcher::where('user_id', $user->id)->first();
            if (!$currentDispatcher || $dispatcher->id !== $currentDispatcher->id) {
                return $this->error('Access denied', 403);
            }
        }

        $today = now()->startOfDay();
        
        $pickupDeliveries = PickupDelivery::with(['shipment'])
            ->where('dispatcher_id', $dispatcher->id)
            ->whereDate('scheduled_date', $today)
            ->orderBy('scheduled_date', 'asc')
            ->get();

        return $this->success($pickupDeliveries);
    }
}
