<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\InvoiceMail;
use App\Models\Customer;
use App\Models\Dispatcher;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Shipment;
use App\Models\ShipmentStatusHistory;
use App\Models\PickupDelivery;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ShipmentController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        $role = $user->role?->slug;
        
        $query = Shipment::with(['customer', 'dispatcher.user', 'warehouse', 'pickupDeliveries']);

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

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('shipment_type')) {
            $query->where('shipment_type', $request->shipment_type);
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->has('dispatcher_id')) {
            $query->where('dispatcher_id', $request->dispatcher_id);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('tracking_number', 'like', "%{$search}%")
                  ->orWhere('sender_name', 'like', "%{$search}%")
                  ->orWhere('receiver_name', 'like', "%{$search}%");
            });
        }

        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $shipments = $query->orderBy('created_at', 'desc')->paginate(20);

        return $this->success($shipments);
    }

    public function store(Request $request)
    {
        $user = auth()->user();
        $role = $user->role?->slug;

        if (in_array($role, ['dispatcher', 'accountant'])) {
            return $this->error('You do not have permission to create shipments', 403);
        }

        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'sender_name' => 'required|string|max:255',
            'sender_phone' => 'required|string|max:20',
            'sender_address' => 'required',
            'sender_city' => 'required|string|max:100',
            'sender_state' => 'required|string|max:100',
            'receiver_name' => 'required|string|max:255',
            'receiver_phone' => 'required|string|max:20',
            'receiver_address' => 'required',
            'receiver_city' => 'required|string|max:100',
            'receiver_state' => 'required|string|max:100',
            'shipment_type' => 'required|in:parcel,bulk_cargo,doorstep,interstate',
            'weight' => 'nullable|numeric|min:0',
            'dimensions' => 'nullable|string|max:50',
            'number_of_pieces' => 'nullable|integer|min:1',
            'package_type' => 'nullable|string|max:50',
            'declared_value' => 'nullable|numeric|min:0',
            'shipping_cost' => 'nullable|numeric|min:0',
            'is_priority' => 'nullable|boolean',
            'description' => 'nullable|string',
            'delivery_notes' => 'nullable|string',
            'email' => 'nullable|email',
            'scheduled_pickup_date' => 'nullable|date',
            'scheduled_delivery_date' => 'nullable|date',
            'status' => 'nullable|string',
            // Special Handling
            'is_fragile' => 'nullable|boolean',
            'is_hazardous' => 'nullable|boolean',
            'is_perishable' => 'nullable|boolean',
            'is_valuable' => 'nullable|boolean',
            // Financial
            'cod_amount' => 'nullable|numeric|min:0',
            'payment_status' => 'nullable|in:paid,pending,partial,refunded',
            'insurance_cost' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'discount_reason' => 'nullable|string|max:255',
            // Delivery Options
            'delivery_time_slot' => 'nullable|in:morning,afternoon,evening,anytime',
            'signature_required' => 'nullable|boolean',
            'id_verification_required' => 'nullable|boolean',
            'notify_sender_on_delivery' => 'nullable|boolean',
            'notify_receiver_on_pickup' => 'nullable|boolean',
            'contact_preference' => 'nullable|in:call,sms,whatsapp,email',
            // Return Shipment
            'is_return_shipment' => 'nullable|boolean',
            'original_tracking_number' => 'nullable|string|max:50',
            'return_reason' => 'nullable|string|max:255',
            // Customer Reference
            'customer_reference' => 'nullable|string|max:100',
        ]);

        $validated['created_by'] = auth()->id();
        
        if (!isset($validated['status'])) {
            $validated['status'] = 'pending';
        }

        if (!isset($validated['number_of_pieces'])) {
            $validated['number_of_pieces'] = 1;
        }

        $shipment = Shipment::create($validated);

        ShipmentStatusHistory::create([
            'shipment_id' => $shipment->id,
            'status' => $shipment->status,
            'notes' => 'Shipment created',
            'recorded_by' => auth()->id(),
        ]);

        // Auto-create pickup schedule
        $scheduledDate = $validated['scheduled_pickup_date'] ?? now()->addDay();
        $timeSlot = $validated['delivery_time_slot'] ?? 'anytime';
        
        $timeWindowStart = match($timeSlot) {
            'morning' => '08:00:00',
            'afternoon' => '12:00:00',
            'evening' => '16:00:00',
            default => null,
        };
        
        $timeWindowEnd = match($timeSlot) {
            'morning' => '12:00:00',
            'afternoon' => '16:00:00',
            'evening' => '20:00:00',
            default => null,
        };

        PickupDelivery::create([
            'shipment_id' => $shipment->id,
            'type' => 'pickup',
            'scheduled_date' => $scheduledDate,
            'time_window_start' => $timeWindowStart,
            'time_window_end' => $timeWindowEnd,
            'status' => 'scheduled',
            'created_by' => auth()->id(),
            // Pickup location from sender info
            'pickup_address' => $validated['sender_address'],
            'pickup_city' => $validated['sender_city'],
            'pickup_state' => $validated['sender_state'],
            'pickup_phone' => $validated['sender_phone'],
            // Delivery location from receiver info
            'delivery_address' => $validated['receiver_address'],
            'delivery_city' => $validated['receiver_city'],
            'delivery_state' => $validated['receiver_state'],
            'delivery_phone' => $validated['receiver_phone'],
        ]);

        // Auto-create delivery schedule
        $scheduledDeliveryDate = $validated['scheduled_delivery_date'] ?? now()->addDays(3);

        PickupDelivery::create([
            'shipment_id' => $shipment->id,
            'type' => 'delivery',
            'scheduled_date' => $scheduledDeliveryDate,
            'time_window_start' => $timeWindowStart,
            'time_window_end' => $timeWindowEnd,
            'status' => 'scheduled',
            'created_by' => auth()->id(),
            // Pickup location from sender info
            'pickup_address' => $validated['sender_address'],
            'pickup_city' => $validated['sender_city'],
            'pickup_state' => $validated['sender_state'],
            'pickup_phone' => $validated['sender_phone'],
            // Delivery location from receiver info
            'delivery_address' => $validated['receiver_address'],
            'delivery_city' => $validated['receiver_city'],
            'delivery_state' => $validated['receiver_state'],
            'delivery_phone' => $validated['receiver_phone'],
        ]);

        return $this->success($shipment, 'Shipment created successfully', 201);
    }

    public function show(Shipment $shipment)
    {
        $user = auth()->user();
        $role = $user->role?->slug;

        if ($role === 'dispatcher') {
            $dispatcher = Dispatcher::where('user_id', $user->id)->first();
            if (!$dispatcher || $shipment->dispatcher_id !== $dispatcher->id) {
                return $this->error('Access denied', 403);
            }
        }

        $shipment->load([
            'customer',
            'dispatcher.user',
            'warehouse',
            'vehicle',
            'createdBy',
            'assignedBy',
            'statusHistory.recordedBy',
            'invoice',
        ]);

        return $this->success($shipment);
    }

    public function update(Request $request, Shipment $shipment)
    {
        $user = auth()->user();
        $role = $user->role?->slug;

        if (in_array($role, ['dispatcher', 'accountant'])) {
            return $this->error('You do not have permission to update shipments', 403);
        }

        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'sender_name' => 'sometimes|string|max:255',
            'sender_phone' => 'sometimes|string|max:20',
            'sender_address' => 'sometimes',
            'sender_city' => 'sometimes|string|max:100',
            'sender_state' => 'sometimes|string|max:100',
            'receiver_name' => 'sometimes|string|max:255',
            'receiver_phone' => 'sometimes|string|max:20',
            'receiver_address' => 'sometimes',
            'receiver_city' => 'sometimes|string|max:100',
            'receiver_state' => 'sometimes|string|max:100',
            'shipment_type' => 'sometimes|in:parcel,bulk_cargo,doorstep,interstate',
            'weight' => 'nullable|numeric|min:0',
            'dimensions' => 'nullable|string|max:50',
            'number_of_pieces' => 'nullable|integer|min:1',
            'package_type' => 'nullable|string|max:50',
            'declared_value' => 'nullable|numeric|min:0',
            'shipping_cost' => 'nullable|numeric|min:0',
            'is_priority' => 'nullable|boolean',
            'description' => 'nullable|string',
            'delivery_notes' => 'nullable|string',
            'email' => 'nullable|email',
            'status' => 'nullable|string',
            'scheduled_pickup_date' => 'nullable|date',
            'scheduled_delivery_date' => 'nullable|date',
            // Special Handling
            'is_fragile' => 'nullable|boolean',
            'is_hazardous' => 'nullable|boolean',
            'is_perishable' => 'nullable|boolean',
            'is_valuable' => 'nullable|boolean',
            // Financial
            'cod_amount' => 'nullable|numeric|min:0',
            'payment_status' => 'nullable|in:paid,pending,partial,refunded',
            'insurance_cost' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'discount_reason' => 'nullable|string|max:255',
            // Delivery Options
            'delivery_time_slot' => 'nullable|in:morning,afternoon,evening,anytime',
            'signature_required' => 'nullable|boolean',
            'id_verification_required' => 'nullable|boolean',
            'notify_sender_on_delivery' => 'nullable|boolean',
            'notify_receiver_on_pickup' => 'nullable|boolean',
            'contact_preference' => 'nullable|in:call,sms,whatsapp,email',
            // Return Shipment
            'is_return_shipment' => 'nullable|boolean',
            'original_tracking_number' => 'nullable|string|max:50',
            'return_reason' => 'nullable|string|max:255',
            // Customer Reference
            'customer_reference' => 'nullable|string|max:100',
            // Recipient Info
            'recipient_name' => 'nullable|string|max:255',
            'recipient_signature' => 'nullable|string|max:500',
            // Delivery Attempts
            'delivery_attempts' => 'nullable|integer|min:0',
            'last_delivery_attempt' => 'nullable|date',
        ]);

        $shipment->update($validated);

        return $this->success($shipment, 'Shipment updated successfully');
    }

    public function destroy(Shipment $shipment)
    {
        $user = auth()->user();
        $role = $user->role?->slug;

        if (in_array($role, ['dispatcher', 'customer_service', 'warehouse_officer', 'accountant'])) {
            return $this->error('You do not have permission to delete shipments', 403);
        }

        if (in_array($shipment->status, ['in_transit', 'out_for_delivery', 'delivered'])) {
            return $this->error('Cannot delete shipment in transit or delivered', 400);
        }

        $shipment->delete();

        return $this->success(null, 'Shipment deleted successfully');
    }

    public function updateStatus(Request $request, Shipment $shipment)
    {
        $user = auth()->user();
        $role = $user->role?->slug;

        if ($role === 'dispatcher') {
            $dispatcher = Dispatcher::where('user_id', $user->id)->first();
            if (!$dispatcher || $shipment->dispatcher_id !== $dispatcher->id) {
                return $this->error('Access denied', 403);
            }
        }

        $validated = $request->validate([
            'status' => 'required|in:pending,picked_up,at_warehouse,in_transit,out_for_delivery,delivered,failed',
            'notes' => 'nullable|string',
            'location' => 'nullable|string|max:255',
        ]);

        $oldStatus = $shipment->status;
        $shipment->update(['status' => $validated['status']]);

        if ($validated['status'] === 'picked_up') {
            $shipment->update(['actual_pickup_date' => now()]);
        }

        if ($validated['status'] === 'delivered') {
            $shipment->update(['actual_delivery_date' => now()]);
            
            if ($shipment->dispatcher) {
                $shipment->dispatcher->increment('total_deliveries');
                $shipment->dispatcher->increment('successful_deliveries');
            }

            // Auto-generate invoice for delivered shipment
            $this->autoGenerateInvoice($shipment);
        }

        if ($validated['status'] === 'failed' && $request->has('failure_reason')) {
            $shipment->update(['failure_reason' => $request->failure_reason]);
        }

        ShipmentStatusHistory::create([
            'shipment_id' => $shipment->id,
            'status' => $validated['status'],
            'notes' => $validated['notes'] ?? null,
            'location' => $validated['location'] ?? null,
            'recorded_by' => auth()->id(),
        ]);

        if ($shipment->dispatcher) {
            Notification::create([
                'user_id' => $shipment->dispatcher->user_id,
                'title' => 'Shipment Status Updated',
                'message' => "Shipment {$shipment->tracking_number} status changed to {$validated['status']}",
                'type' => 'shipment',
                'related_to_type' => Shipment::class,
                'related_to_id' => $shipment->id,
            ]);
        }

        return $this->success($shipment, 'Status updated successfully');
    }

    public function assignDispatcher(Request $request, Shipment $shipment)
    {
        $validated = $request->validate([
            'dispatcher_id' => 'required|exists:dispatchers,id',
            'vehicle_id' => 'nullable|exists:vehicles,id',
        ]);

        $dispatcher = Dispatcher::findOrFail($validated['dispatcher_id']);

        $shipment->update([
            'dispatcher_id' => $validated['dispatcher_id'],
            'vehicle_id' => $validated['vehicle_id'] ?? $dispatcher->vehicle_id,
            'assigned_by' => auth()->id(),
        ]);

        Notification::create([
            'user_id' => $dispatcher->user_id,
            'title' => 'New Shipment Assigned',
            'message' => "Shipment {$shipment->tracking_number} has been assigned to you",
            'type' => 'shipment',
            'related_to_type' => Shipment::class,
            'related_to_id' => $shipment->id,
        ]);

        // Sync dispatcher to pickup/delivery tasks
        \App\Models\PickupDelivery::where('shipment_id', $shipment->id)->update([
            'dispatcher_id' => $validated['dispatcher_id']
        ]);

        return $this->success($shipment, 'Dispatcher assigned successfully');
    }

    public function uploadProof(Request $request, Shipment $shipment)
    {
        $request->validate([
            'proof_of_delivery' => 'required|image|max:2048',
            'delivery_notes' => 'nullable|string',
        ]);

        $path = $request->file('proof_of_delivery')->store('proofs', 'public');

        $shipment->update([
            'proof_of_delivery' => $path,
            'delivery_notes' => $request->delivery_notes,
        ]);

        return $this->success(['proof_of_delivery' => $path], 'Proof uploaded successfully');
    }

    public function track($trackingNumber)
    {
        $shipment = Shipment::where('tracking_number', $trackingNumber)
            ->with(['customer', 'statusHistory.recordedBy', 'dispatcher.user'])
            ->first();

        if (!$shipment) {
            return $this->error('Shipment not found', 404);
        }

        return $this->success([
            'tracking_number' => $shipment->tracking_number,
            'status' => $shipment->status,
            'shipment_type' => $shipment->shipment_type,
            'sender' => [
                'name' => $shipment->sender_name,
                'address' => $shipment->sender_address,
            ],
            'receiver' => [
                'name' => $shipment->receiver_name,
                'address' => $shipment->receiver_address,
                'city' => $shipment->receiver_city,
                'state' => $shipment->receiver_state,
            ],
            'scheduled_delivery' => $shipment->scheduled_delivery_date,
            'timeline' => $shipment->statusHistory->map(function ($history) {
                return [
                    'status' => $history->status,
                    'location' => $history->location,
                    'notes' => $history->notes,
                    'timestamp' => $history->created_at->toIso8601String(),
                ];
            }),
        ]);
    }

    private function autoGenerateInvoice(Shipment $shipment): ?Invoice
    {
        // Check if customer exists and has auto_invoice enabled
        if (!$shipment->customer) {
            return null;
        }

        $customer = $shipment->customer;
        
        // Check if auto_invoice is enabled for this customer (default true)
        if (isset($customer->auto_invoice) && !$customer->auto_invoice) {
            return null;
        }

        // Check if invoice already exists for this shipment
        $existingInvoice = Invoice::where('shipment_id', $shipment->id)->first();
        if ($existingInvoice) {
            return $existingInvoice;
        }

        // Get tax rate from settings (default 7.5%)
        $taxRate = \App\Models\Setting::get('default_tax_rate', 7.5);
        
        $shippingCost = $shipment->shipping_cost ?? 0;
        $taxAmount = $shippingCost * ($taxRate / 100);
        $totalAmount = $shippingCost + $taxAmount;

        // Create invoice
        $invoice = Invoice::create([
            'invoice_number' => Invoice::generateInvoiceNumber(),
            'shipment_id' => $shipment->id,
            'customer_id' => $shipment->customer_id,
            'subtotal' => $shippingCost,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'discount' => 0,
            'total_amount' => $totalAmount,
            'status' => 'pending',
            'due_date' => now()->addDays(30),
            'notes' => "Auto-generated invoice for shipment {$shipment->tracking_number}",
            'created_by' => auth()->id() ?? 1,
        ]);

        // Add invoice item
        \App\Models\InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => "Shipping cost - Shipment {$shipment->tracking_number}",
            'quantity' => 1,
            'unit_price' => $shippingCost,
            'amount' => $shippingCost,
        ]);

        // Update customer account balance
        $customer->increment('account_balance', $totalAmount);

        // Send invoice email to customer
        $email = $customer->email ?? $shipment->email;
        if ($email) {
            try {
                Mail::to($email)->send(new InvoiceMail($invoice));
            } catch (\Exception $e) {
                \Log::error('Failed to send invoice email', [
                    'invoice_id' => $invoice->id,
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Create notification
        Notification::create([
            'user_id' => $shipment->customer->user_id ?? null,
            'title' => 'New Invoice Generated',
            'message' => "Invoice {$invoice->invoice_number} has been generated for shipment {$shipment->tracking_number}. Amount: ₦" . number_format($totalAmount, 2),
            'type' => 'invoice',
            'related_to_type' => Invoice::class,
            'related_to_id' => $invoice->id,
        ]);

        return $invoice;
    }
}
