<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Traits\PartnerModuleTrait;
use App\Models\PartnerCustomer;
use App\Models\PartnerProduct;
use App\Models\FulfillmentRequest;
use App\Models\FulfillmentActivityLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Dispatcher;
use App\Models\User;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PartnerFulfillmentController extends Controller
{
    use PartnerModuleTrait;
    public function requests(Request $request)
    {
        try {
            $this->checkModuleEnabled();
        } catch (\Exception $e) {
            return $this->success(['data' => [], 'meta' => []]);
        }

        $user = auth()->user();

        $query = FulfillmentRequest::with([
            'partnerCustomer.customer',
            'partnerCustomer.partner',
            'partnerCustomer.warehouse',
            'partnerProduct',
            'staff',
            'dispatcher.user',
            'invoice'
        ]);

        // Role-based filtering
        if ($user && $user->role && !in_array($user->role->slug, ['super_admin', 'operations_manager', 'operations'])) {
            if ($user->role->slug === 'dispatcher') {
                $dispatcher = Dispatcher::firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'license_number' => 'DL-' . strtoupper(uniqid()),
                        'license_expiry' => now()->addYear(),
                        'is_available' => true,
                    ]
                );
                $query->where('dispatcher_id', $dispatcher->id);
            } else {
                $query->where('staff_id', $user->id);
            }
        }

        if ($request->partner_customer_id) {
            $query->where('partner_customer_id', $request->partner_customer_id);
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        if ($request->staff_id) {
            $query->where('staff_id', $request->staff_id);
        }

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('delivery_notes', 'like', "%{$request->search}%")
                    ->orWhere('delivery_phone', 'like', "%{$request->search}%")
                    ->orWhere('delivery_address', 'like', "%{$request->search}%")
                    ->orWhereHas('partnerCustomer', function ($pc) use ($request) {
                        $pc->where('customer_name', 'like', "%{$request->search}%")
                            ->orWhereHas('customer', function ($c) use ($request) {
                                $c->where('name', 'like', "%{$request->search}%")
                                    ->orWhere('company_name', 'like', "%{$request->search}%");
                            })
                            ->orWhereHas('partner', function ($p) use ($request) {
                                $p->where('company', 'like', "%{$request->search}%")
                                    ->orWhere('name', 'like', "%{$request->search}%");
                            });
                    })
                    ->orWhereHas('partnerProduct', function ($pp) use ($request) {
                        $pp->where('name', 'like', "%{$request->search}%");
                    });
            });
        }

        $requests = $query->orderBy('id', 'desc')->paginate($request->per_page ?? 20);

        return $this->success($requests);
    }

    public function createRequest(Request $request)
    {
        $this->checkModuleEnabled();
        $validated = $request->validate([
            'partner_customer_id' => 'required|exists:partner_customers,id',
            'partner_product_id' => 'nullable|exists:partner_products,id',
            'quantity' => 'nullable|integer|min:1',
            'delivery_address' => 'required|string',
            'delivery_city' => 'nullable|string',
            'delivery_state' => 'nullable|string',
            'delivery_phone' => 'required|string',
            'delivery_notes' => 'nullable|string',
            'requested_by' => 'nullable|string',
            'notes' => 'nullable|string',
            'request_type' => 'nullable|string|in:pickup,delivery',
            'contact_person' => 'nullable|string',
            'preferred_delivery_date' => 'nullable|date',
            'preferred_delivery_time_window' => 'nullable|string',
        ]);

        $partnerCustomer = PartnerCustomer::with('staff')->findOrFail($validated['partner_customer_id']);

        // Assign to staff if not assigned
        if (!$partnerCustomer->staff_id) {
            return $this->error('No staff assigned to this customer. Please assign a staff first.', 400);
        }

        $validated['staff_id'] = $partnerCustomer->staff_id;
        $validated['requested_at'] = now();

        // Handle product if provided
        if (!empty($validated['partner_product_id'])) {
            $product = PartnerProduct::findOrFail($validated['partner_product_id']);
            if ($product->quantity < ($validated['quantity'] ?? 1)) {
                return $this->error('Insufficient stock. Available: ' . $product->quantity, 400);
            }
            
            // Set COD fields
            $validated['cod_amount'] = ($product->unit_cost ?? 0) * ($validated['quantity'] ?? 1);
            $validated['remittance_amount'] = $validated['cod_amount']; // delivery_cost is 0 initially
            
            $product->decrement('quantity', $validated['quantity'] ?? 1);
        }

        if (empty($validated['delivery_notes']) && !empty($validated['contact_person'])) {
            $validated['delivery_notes'] = $validated['contact_person'];
        }

        $fulfillmentRequest = FulfillmentRequest::create($validated);

        // Notify assigned staff
        Notification::create([
            'user_id' => $fulfillmentRequest->staff_id,
            'title' => 'New Fulfillment Request',
            'message' => "New request #{$fulfillmentRequest->id} from " . ($partnerCustomer->customer->name ?? 'Partner Customer'),
            'type' => 'fulfillment',
            'related_to_type' => FulfillmentRequest::class,
            'related_to_id' => $fulfillmentRequest->id,
        ]);

        // Notify admins
        $this->notifyAdmins(
            'New Fulfillment Request',
            "Request #{$fulfillmentRequest->request_number} created for " . ($partnerCustomer->customer->name ?? 'Partner Customer'),
            'fulfillment',
            $fulfillmentRequest
        );

        // Log activity
        FulfillmentActivityLog::create([
            'fulfillment_request_id' => $fulfillmentRequest->id,
            'user_id' => auth()->id(),
            'action' => 'created',
            'notes' => 'Fulfillment request created',
        ]);

        // Notify partner via Bot (Asynchronous)
        dispatch(function () use ($fulfillmentRequest) {
            try {
                $botEngine = app(\App\Services\Bot\BotEngine::class);
                $botEngine->notifyPartnerOrderCreated($fulfillmentRequest->load(['partnerCustomer.partner', 'partnerProduct']));
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("Failed to send order creation notification to partner: " . $e->getMessage());
            }
        })->afterResponse();

        return $this->success($fulfillmentRequest->load(['partnerCustomer.customer', 'partnerCustomer.partner', 'partnerProduct', 'staff']), 'Fulfillment request created successfully', 201);
    }

    public function showRequest($id)
    {
        $this->checkModuleEnabled();
        $request = FulfillmentRequest::with([
            'partnerCustomer.customer',
            'partnerCustomer.partner',
            'partnerProduct',
            'staff',
            'picker',
            'dispatcher.user',
            'shipment',
            'invoice',
            'activities.user'
        ])->findOrFail($id);

        return $this->success($request);
    }

    public function acknowledgeRequest(Request $request, $id)
    {
        $this->checkModuleEnabled();
        
        $validated = $request->validate([
            'delivery_cost' => 'required|numeric|min:0',
        ]);

        $fulfillmentRequest = FulfillmentRequest::findOrFail($id);

        if (!in_array($fulfillmentRequest->status, ['pending', 'processing', 'rejected', 'awaiting_reschedule'])) {
            return $this->error('Request cannot be acknowledged in current status', 400);
        }

        DB::transaction(function () use ($fulfillmentRequest, $validated) {
            $fulfillmentRequest->update([
                'status' => 'awaiting_partner_action',
                'delivery_cost' => $validated['delivery_cost'],
                'picked_by' => auth()->id(),
            ]);

            FulfillmentActivityLog::create([
                'fulfillment_request_id' => $fulfillmentRequest->id,
                'user_id' => auth()->id(),
                'action' => 'acknowledged',
                'notes' => 'Cost set: ' . $validated['delivery_cost'] . ' - Awaiting partner action',
            ]);
        });

        // Non-critical: send notification after response
        dispatch(function () use ($fulfillmentRequest, $validated) {
            Notification::create([
                'user_id' => $fulfillmentRequest->partnerCustomer->partner_id,
                'title' => 'Delivery Cost Set',
                'message' => "Cost for request #{$fulfillmentRequest->id} set to ₦" . number_format($validated['delivery_cost'], 2) . ". Please accept to proceed.",
                'type' => 'fulfillment',
                'related_to_type' => FulfillmentRequest::class,
                'related_to_id' => $fulfillmentRequest->id,
            ]);
        })->afterResponse();

        return $this->success($fulfillmentRequest->load(['partnerCustomer.customer', 'partnerProduct']), 'Request acknowledged. Waiting for partner acceptance.');
    }

    public function acceptRequest($id)
    {
        $this->checkModuleEnabled();
        $fulfillmentRequest = FulfillmentRequest::findOrFail($id);

        if ($fulfillmentRequest->status !== 'awaiting_partner_action' && $fulfillmentRequest->status !== 'rejected') {
            return $this->error('Request is not awaiting partner action', 400);
        }

        DB::transaction(function () use ($fulfillmentRequest) {
            $fulfillmentRequest->update([
                'status' => 'accepted',
            ]);

            FulfillmentActivityLog::create([
                'fulfillment_request_id' => $fulfillmentRequest->id,
                'user_id' => auth()->id(),
                'action' => 'accepted',
                'notes' => 'Partner accepted the delivery cost',
            ]);
        });

        dispatch(function () use ($fulfillmentRequest) {
            Notification::create([
                'user_id' => $fulfillmentRequest->staff_id,
                'title' => 'Partner Accepted Cost',
                'message' => "Partner accepted delivery cost for request #{$fulfillmentRequest->id}",
                'type' => 'fulfillment',
                'related_to_type' => FulfillmentRequest::class,
                'related_to_id' => $fulfillmentRequest->id,
            ]);
        })->afterResponse();

        return $this->success($fulfillmentRequest->load(['partnerCustomer.customer', 'partnerProduct']), 'Request accepted');
    }

    public function rejectRequest(Request $request, $id)
    {
        $this->checkModuleEnabled();
        
        $validated = $request->validate([
            'reason' => 'required|string',
        ]);

        $fulfillmentRequest = FulfillmentRequest::findOrFail($id);

        if ($fulfillmentRequest->status !== 'awaiting_partner_action') {
            return $this->error('Request is not awaiting partner action', 400);
        }

        DB::transaction(function () use ($fulfillmentRequest, $validated) {
            $fulfillmentRequest->update([
                'status' => 'rejected',
                'partner_response' => $validated['reason'],
            ]);

            FulfillmentActivityLog::create([
                'fulfillment_request_id' => $fulfillmentRequest->id,
                'user_id' => auth()->id(),
                'action' => 'rejected',
                'notes' => 'Partner rejected: ' . $validated['reason'],
            ]);
        });

        dispatch(function () use ($fulfillmentRequest, $validated) {
            Notification::create([
                'user_id' => $fulfillmentRequest->staff_id,
                'title' => 'Partner Rejected Cost',
                'message' => "Partner rejected delivery cost for request #{$fulfillmentRequest->id}. Reason: {$validated['reason']}",
                'type' => 'fulfillment',
                'related_to_type' => FulfillmentRequest::class,
                'related_to_id' => $fulfillmentRequest->id,
            ]);
        })->afterResponse();

        return $this->success($fulfillmentRequest->load(['partnerCustomer.customer', 'partnerProduct']), 'Request rejected');
    }

    public function assignDispatcher(Request $request, $id)
    {
        $this->checkModuleEnabled();
        $validated = $request->validate([
            'dispatcher_id' => 'required|exists:dispatchers,id',
        ]);

        $fulfillmentRequest = FulfillmentRequest::findOrFail($id);

        if (!in_array($fulfillmentRequest->status, ['acknowledged', 'accepted'])) {
            return $this->error('Request must be acknowledged/accepted before assigning dispatcher', 400);
        }

        $dispatcher = \App\Models\Dispatcher::findOrFail($validated['dispatcher_id']);

        DB::transaction(function () use ($fulfillmentRequest, $validated) {
            $fulfillmentRequest->update([
                'status' => 'assigned',
                'dispatcher_id' => $validated['dispatcher_id'],
            ]);

            FulfillmentActivityLog::create([
                'fulfillment_request_id' => $fulfillmentRequest->id,
                'user_id' => auth()->id(),
                'action' => 'dispatcher_assigned',
                'notes' => 'Dispatcher assigned for delivery',
            ]);
        });

        // Non-critical notifications after response
        dispatch(function () use ($fulfillmentRequest, $dispatcher) {
            Notification::create([
                'user_id' => $dispatcher->user_id,
                'title' => 'New Job Assigned',
                'message' => "You have been assigned fulfillment request #{$fulfillmentRequest->id}",
                'type' => 'delivery',
                'related_to_type' => FulfillmentRequest::class,
                'related_to_id' => $fulfillmentRequest->id,
            ]);

            try {
                $botEngine = app(\App\Services\Bot\BotEngine::class);
                $notified = $botEngine->notifyDispatcherFulfillmentAssignment(
                    $fulfillmentRequest->load(['dispatcher.user', 'partnerProduct'])
                );

                if (!$notified) {
                    \Illuminate\Support\Facades\Log::warning('Dispatcher bot notification was not delivered for fulfillment assignment', [
                        'fulfillment_request_id' => $fulfillmentRequest->id,
                        'dispatcher_id' => $fulfillmentRequest->dispatcher_id,
                    ]);
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to notify dispatcher for fulfillment assignment: ' . $e->getMessage(), [
                    'fulfillment_request_id' => $fulfillmentRequest->id,
                ]);
            }
        })->afterResponse();

        return $this->success($fulfillmentRequest->load(['partnerCustomer.customer', 'partnerProduct', 'dispatcher.user']), 'Dispatcher assigned successfully');
    }

    public function startDelivery($id)
    {
        $this->checkModuleEnabled();
        $fulfillmentRequest = FulfillmentRequest::findOrFail($id);

        $user = auth()->user();
        if ($user->role?->slug === 'dispatcher') {
            $dispatcher = Dispatcher::where('user_id', $user->id)->first();
            if (!$dispatcher || $fulfillmentRequest->dispatcher_id !== $dispatcher->id) {
                return $this->error('Access denied', 403);
            }
        }

        if (!in_array($fulfillmentRequest->status, ['assigned', 'in_progress'])) {
            return $this->error('Request must be assigned to start delivery', 400);
        }

        DB::transaction(function () use ($fulfillmentRequest) {
            $fulfillmentRequest->update([
                'status' => 'in_transit',
            ]);

            FulfillmentActivityLog::create([
                'fulfillment_request_id' => $fulfillmentRequest->id,
                'user_id' => auth()->id(),
                'action' => 'in_transit',
                'notes' => 'Dispatcher started the delivery',
            ]);
        });

        return $this->success($fulfillmentRequest->load(['partnerCustomer.customer', 'partnerProduct']), 'Delivery started');
    }

    public function completeRequest(Request $request, $id)
    {
        $this->checkModuleEnabled();
        $fulfillmentRequest = FulfillmentRequest::findOrFail($id);

        $user = auth()->user();
        if ($user->role?->slug === 'dispatcher') {
            $dispatcher = Dispatcher::where('user_id', $user->id)->first();
            if (!$dispatcher || $fulfillmentRequest->dispatcher_id !== $dispatcher->id) {
                return $this->error('Access denied', 403);
            }
        }

        $isAlreadyDelivered = $fulfillmentRequest->status === 'delivered';

        if (!$isAlreadyDelivered && !in_array($fulfillmentRequest->status, ['assigned', 'in_progress', 'in_transit', 'out_for_delivery'])) {
            return $this->error('Request must be assigned or in transit to complete', 400);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string',
            'amount_collected' => 'nullable|numeric|min:0',
            'proof_photo' => 'nullable|string', // Base64 string or URL
        ]);

        $amountCollected = $validated['amount_collected'] ?? $fulfillmentRequest->cod_amount;
        
        DB::transaction(function () use ($fulfillmentRequest, $validated, $amountCollected, $isAlreadyDelivered) {
            $fulfillmentRequest->update([
                'status' => 'delivered',
                'amount_collected' => $amountCollected,
                'completed_at' => now(),
                'notes' => $validated['notes'] ?? $fulfillmentRequest->notes,
                'proof_photo' => $validated['proof_photo'] ?? null,
            ]);

            // Notify partner
            Notification::create([
                'user_id' => $fulfillmentRequest->partnerCustomer->partner_id,
                'title' => 'Order Delivered',
                'message' => "Your order #{$fulfillmentRequest->id} has been delivered successfully.",
                'type' => 'delivery',
                'related_to_type' => FulfillmentRequest::class,
                'related_to_id' => $fulfillmentRequest->id,
            ]);

            // Notify assigned staff
            Notification::create([
                'user_id' => $fulfillmentRequest->staff_id,
                'title' => 'Order Delivered',
                'message' => "Fulfillment request #{$fulfillmentRequest->id} has been delivered.",
                'type' => 'delivery',
                'related_to_type' => FulfillmentRequest::class,
                'related_to_id' => $fulfillmentRequest->id,
            ]);

            if (!$isAlreadyDelivered) {
                // Inventory tracking rules...
                $product = $fulfillmentRequest->partnerProduct;
                $stockWasRestored = $product
                    && $fulfillmentRequest->fail_reason === 'Customer rejected the goods'
                    && $fulfillmentRequest->failed_at === null; // admin cleared failed_at on reschedule

                if ($stockWasRestored) {
                    // Check if inventory was already restored (failed orders have their stock returned)
                    $inventoryRestored = \App\Models\FulfillmentActivityLog::where('fulfillment_request_id', $fulfillmentRequest->id)
                        ->where('notes', 'like', '%[Inventory Restored]%')
                        ->exists();

                    if ($inventoryRestored && $fulfillmentRequest->partnerProduct) {
                        \Log::info("Re-deducting stock for previously failed order {$fulfillmentRequest->id}");
                        $fulfillmentRequest->partnerProduct->decrement('quantity', $fulfillmentRequest->quantity ?? 1);
                    }
                    Log::info("Inventory re-decremented on delivery (after prior restore) for request {$fulfillmentRequest->id}: qty={$fulfillmentRequest->quantity}");
                }

                // Update dispatcher stats
                if ($fulfillmentRequest->dispatcher) {
                    $fulfillmentRequest->dispatcher->increment('total_deliveries');
                    $fulfillmentRequest->dispatcher->increment('successful_deliveries');
                }

                FulfillmentActivityLog::create([
                    'fulfillment_request_id' => $fulfillmentRequest->id,
                    'user_id' => auth()->id(),
                    'action' => 'delivered',
                    'notes' => 'Order delivered successfully' . ($validated['notes'] ? ' | Notes: ' . $validated['notes'] : ''),
                ]);
            } else {
                // Just log the update
                FulfillmentActivityLog::create([
                    'fulfillment_request_id' => $fulfillmentRequest->id,
                    'user_id' => auth()->id(),
                    'action' => 'updated_delivery',
                    'notes' => 'Delivery details updated (notes/photo)' . ($validated['notes'] ? ' | Notes: ' . $validated['notes'] : ''),
                ]);
            }
        });

        if (!$isAlreadyDelivered) {
            // Notify partner via Bot (Asynchronous to prevent hanging)
            dispatch(function () use ($fulfillmentRequest) {
                try {
                    $botEngine = app(\App\Services\Bot\BotEngine::class);
                    $botEngine->notifyPartnerOrderDelivered($fulfillmentRequest);
                } catch (\Exception $e) {
                    \Log::error("Failed to send delivery notification to partner: " . $e->getMessage());
                }
            })->afterResponse();
        }

        return $this->success($fulfillmentRequest->load(['partnerCustomer.customer', 'partnerProduct']), 'Delivery completed successfully');
    }

    public function failDelivery(Request $request, $id)
    {
        $this->checkModuleEnabled();

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
            'notes' => 'nullable|string',
            'proof_photo' => 'nullable|string',
        ]);

        $fulfillmentRequest = FulfillmentRequest::findOrFail($id);
        
        // Define failure reasons that trigger rescheduling
        $rescheduleReasons = ['not_reachable'];
        $isReschedule = in_array($validated['reason'], $rescheduleReasons);
        
        // Map slug to label
        $reasonLabels = [
            'customer_rejected' => 'Customer rejected the goods',
            'not_reachable' => 'Customer not reachable',
        ];
        
        $reasonLabel = $reasonLabels[$validated['reason']] ?? $validated['reason'];

        DB::transaction(function () use ($fulfillmentRequest, $validated, $isReschedule, $reasonLabel) {
            // Handle inventory and rescheduling
            if ($isReschedule) {
                // Rescheduled: goods remain out for re-delivery, so do NOT restore inventory.
                // The stock was already decremented on order creation and will be correct
                // when the order is eventually delivered or fails permanently.
                $fulfillmentRequest->update([
                    'status' => 'awaiting_reschedule',
                    'requested_at' => now()->addDay()->startOfDay(),
                    'failed_at' => now(), // Keep track of when it failed (used to detect re-deliver flow)
                    'fail_reason' => $validated['reason'],
                    'failed_by' => auth()->user()->name,
                    'pickup_delivery_id' => null, // Unlink from current delivery
                    'notes' => ($fulfillmentRequest->notes ? $fulfillmentRequest->notes . "\n" : "") . 'Automatic reschedule (not reachable) on ' . now()->format('Y-m-d H:i'),
                ]);
            } else {
                // Final failure: restore product quantity since the goods were not delivered.
                // Guard against null product (orders without a linked product).
                if ($fulfillmentRequest->partnerProduct && $fulfillmentRequest->failed_at === null) {
                    // Only restore if not already restored (idempotent guard using failed_at).
                    $fulfillmentRequest->partnerProduct->increment('quantity', $fulfillmentRequest->quantity ?? 1);
                    
                    \App\Models\FulfillmentActivityLog::create([
                        'fulfillment_request_id' => $fulfillmentRequest->id,
                        'user_id' => auth()->id(),
                        'action' => 'inventory_restored',
                        'notes' => '[Inventory Restored] Stock returned due to final failure: ' . ($validated['reason'] ?? 'customer_rejected'),
                    ]);
                    Log::info("Inventory restored on final failure for request {$fulfillmentRequest->id}: qty={$fulfillmentRequest->quantity}");
                }

                $fulfillmentRequest->update([
                    'status' => 'failed',
                    'failed_at' => now(),
                    'fail_reason' => $validated['reason'],
                    'failed_by' => auth()->user()->name,
                    'notes' => $validated['notes'] ?? $fulfillmentRequest->notes,
                ]);
            }

            // Notify partner
            Notification::create([
                'user_id' => $fulfillmentRequest->partnerCustomer->partner_id,
                'title' => 'Delivery Failed',
                'message' => "Delivery for order #{$fulfillmentRequest->id} failed. Reason: {$reasonLabel}",
                'type' => 'delivery',
                'related_to_type' => FulfillmentRequest::class,
                'related_to_id' => $fulfillmentRequest->id,
            ]);

            // Notify staff
            Notification::create([
                'user_id' => $fulfillmentRequest->staff_id,
                'title' => 'Delivery Failed',
                'message' => "Delivery for request #{$fulfillmentRequest->id} failed.",
                'type' => 'delivery',
                'related_to_type' => FulfillmentRequest::class,
                'related_to_id' => $fulfillmentRequest->id,
            ]);

            // Update dispatcher stats
            if ($fulfillmentRequest->dispatcher && !$isReschedule) {
                $fulfillmentRequest->dispatcher->increment('total_deliveries');
            }

            // Log activity
            FulfillmentActivityLog::create([
                'fulfillment_request_id' => $fulfillmentRequest->id,
                'user_id' => auth()->id(),
                'action' => 'failed',
                'notes' => 'Delivery failed: ' . $reasonLabel . ($validated['notes'] ? ' | Notes: ' . $validated['notes'] : ''),
            ]);

            if ($isReschedule) {
                FulfillmentActivityLog::create([
                    'fulfillment_request_id' => $fulfillmentRequest->id,
                    'user_id' => auth()->id(),
                    'action' => 'rescheduled',
                    'notes' => 'New delivery attempt scheduled for tomorrow',
                ]);
            }
        });

        return $this->success($fulfillmentRequest->fresh(), 'Delivery marked as failed' . ($isReschedule ? ' and rescheduled for tomorrow' : ''));
    }

    public function rescheduleRequest(Request $request, $id)
    {
        $this->checkModuleEnabled();
        $fulfillmentRequest = FulfillmentRequest::findOrFail($id);

        if ($fulfillmentRequest->status !== 'failed') {
            return $this->error('Only failed orders can be rescheduled', 400);
        }

        // Update the existing order to avoid duplicates
        $fulfillmentRequest->update([
            'status' => 'awaiting_reschedule',
            'requested_at' => now()->addDay()->startOfDay(),
            'failed_at' => null,
            'fail_reason' => null,
            'failed_by' => null,
            'completed_at' => null,
            'pickup_delivery_id' => null,
            'notes' => ($fulfillmentRequest->notes ? $fulfillmentRequest->notes . "\n" : "") . 'Manual reschedule by admin on ' . now()->format('Y-m-d H:i'),
        ]);

        FulfillmentActivityLog::create([
            'fulfillment_request_id' => $fulfillmentRequest->id,
            'user_id' => auth()->id(),
            'action' => 'rescheduled',
            'notes' => 'Admin manually rescheduled the order for tomorrow',
        ]);

        return $this->success($fulfillmentRequest, 'Order rescheduled successfully');
    }

    public function cancelRequest(Request $request, $id)
    {
        $this->checkModuleEnabled();
        $validated = $request->validate([
            'cancel_reason' => 'required|string',
        ]);

        $fulfillmentRequest = FulfillmentRequest::findOrFail($id);

        if (in_array($fulfillmentRequest->status, ['delivered', 'cancelled'])) {
            return $this->error('Request cannot be cancelled in current status', 400);
        }

        DB::transaction(function () use ($fulfillmentRequest, $validated) {
            // Restore product quantity, but only if:
            // 1. A product is linked to this order.
            // 2. Stock was not already restored by a prior final-failure action.
            //
            // Stock is ONLY pre-restored when an order reaches 'failed' status with reason
            // 'customer_rejected'. An 'awaiting_reschedule' order (not_reachable path) still
            // has its stock out (not restored), so cancelling it must restore stock.
            $product = $fulfillmentRequest->partnerProduct;
            if ($product) {
                // Stock was already restored ONLY if the order is in 'failed' state
                // (final failure where customer_rejected triggered an increment).
                $stockAlreadyRestored = $fulfillmentRequest->status === 'failed';
                if (!$stockAlreadyRestored) {
                    $product->increment('quantity', $fulfillmentRequest->quantity ?? 1);
                    
                    \App\Models\FulfillmentActivityLog::create([
                        'fulfillment_request_id' => $fulfillmentRequest->id,
                        'user_id' => auth()->id(),
                        'action' => 'inventory_restored',
                        'notes' => '[Inventory Restored] Stock returned due to cancellation.',
                    ]);
                    Log::info("Inventory restored on cancel for request {$fulfillmentRequest->id}: qty={$fulfillmentRequest->quantity}");
                }
            }

            $fulfillmentRequest->update([
                'status' => 'cancelled',
                'notes' => ($fulfillmentRequest->notes ? $fulfillmentRequest->notes . "\n" : "") . 'Cancelled: ' . $validated['cancel_reason'],
                'cancelled_by' => 'staff',
            ]);

            // Notify partner
            Notification::create([
                'user_id' => $fulfillmentRequest->partnerCustomer->partner_id,
                'title' => 'Order Cancelled',
                'message' => "Your order #{$fulfillmentRequest->id} has been cancelled. Reason: {$validated['cancel_reason']}",
                'type' => 'fulfillment',
                'related_to_type' => FulfillmentRequest::class,
                'related_to_id' => $fulfillmentRequest->id,
            ]);

            FulfillmentActivityLog::create([
                'fulfillment_request_id' => $fulfillmentRequest->id,
                'user_id' => auth()->id(),
                'action' => 'cancelled',
                'notes' => 'Request cancelled: ' . $validated['cancel_reason'],
            ]);
        });

        return $this->success($fulfillmentRequest->load(['partnerCustomer.customer', 'partnerProduct']), 'Request cancelled successfully');
    }

    public function delayRequest(Request $request, $id)
    {
        $this->checkModuleEnabled();
        $validated = $request->validate([
            'delay_reason' => 'required|string',
            'new_delivery_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $fulfillmentRequest = FulfillmentRequest::findOrFail($id);

        if (in_array($fulfillmentRequest->status, ['delivered', 'cancelled'])) {
            return $this->error('Request cannot be put on hold in current status', 400);
        }

        $fulfillmentRequest->update([
            'delay_reason' => $validated['delay_reason'],
            'new_delivery_date' => $validated['new_delivery_date'] ?? null,
            'notes' => $validated['notes'] ?? $fulfillmentRequest->notes,
            'status' => 'pending', // Reverting to pending as requested for hold/resolution
        ]);

        FulfillmentActivityLog::create([
            'fulfillment_request_id' => $fulfillmentRequest->id,
            'user_id' => auth()->id(),
            'action' => 'pending',
            'notes' => 'Order put on pending/hold. Reason: ' . $validated['delay_reason'] . ($validated['notes'] ? ' | Notes: ' . $validated['notes'] : ''),
        ]);

        return $this->success($fulfillmentRequest->load(['partnerCustomer.customer', 'partnerProduct']), 'Delivery status updated to pending successfully');
    }

    public function confirmDeliveryWindow(Request $request, $id)
    {
        $this->checkModuleEnabled();
        $validated = $request->validate([
            'preferred_delivery_date' => 'nullable|date',
            'preferred_delivery_time_window' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $fulfillmentRequest = FulfillmentRequest::findOrFail($id);

        if (in_array($fulfillmentRequest->status, ['delivered', 'cancelled', 'failed'])) {
            return $this->error('Request cannot be confirmed in current status', 400);
        }

        DB::transaction(function () use ($fulfillmentRequest, $validated) {
            $updates = [
                'delivery_confirmed_at' => now(),
                'status' => 'in_progress', // Move to in-progress as per requirements
            ];

            if (isset($validated['preferred_delivery_date'])) {
                $updates['preferred_delivery_date'] = $validated['preferred_delivery_date'];
            }
            if (isset($validated['preferred_delivery_time_window'])) {
                $updates['preferred_delivery_time_window'] = $validated['preferred_delivery_time_window'];
            }
            if (!empty($validated['notes'])) {
                $updates['notes'] = ($fulfillmentRequest->notes ? $fulfillmentRequest->notes . "\n" : "") . 'Confirmed/Rescheduled: ' . $validated['notes'];
            }

            $fulfillmentRequest->update($updates);

            FulfillmentActivityLog::create([
                'fulfillment_request_id' => $fulfillmentRequest->id,
                'user_id' => auth()->id(),
                'action' => 'delivery_confirmed',
                'notes' => 'Delivery window confirmed with customer via phone' . (!empty($validated['notes']) ? ' | Notes: ' . $validated['notes'] : ''),
            ]);
        });

        // Notify partner via Bot (Asynchronous)
        dispatch(function () use ($fulfillmentRequest) {
            try {
                $botEngine = app(\App\Services\Bot\BotEngine::class);
                $botEngine->notifyPartnerDeliveryConfirmed($fulfillmentRequest->load(['partnerCustomer.partner', 'partnerProduct']));
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("Failed to send delivery confirmation notification to partner: " . $e->getMessage());
            }
        })->afterResponse();

        return $this->success($fulfillmentRequest->fresh()->load(['partnerCustomer.customer', 'partnerProduct']), 'Delivery window confirmed successfully');
    }
}
