<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FulfillmentRequest;
use App\Models\User;
use App\Models\Dispatcher;
use App\Models\PickupDelivery;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ActivityController extends Controller
{
    /**
     * Get daily delivery activity for a specific partner.
     */
    public function partnerDaily(Request $request)
    {
        $user = auth()->user();
        if (!$user || !$user->hasAnyRole(['super_admin', 'operations_manager', 'operations', 'accountant'])) {
            return $this->error('Access denied', 403);
        }

        \Log::info('Partner Activity Request', [
            'partner_id' => $request->partner_id,
            'date' => $request->date,
            'user' => $user->id
        ]);

        $request->validate([
            'partner_id' => 'required|exists:users,id',
            'date' => 'nullable|date',
        ]);

        $date = $request->date ? Carbon::parse($request->date) : Carbon::today();
        $partnerId = $request->partner_id;

        $query = FulfillmentRequest::with([
            'partnerCustomer.partner',
            'partnerProduct',
            'dispatcher.user',
        ])->whereHas('partnerCustomer', function ($q) use ($partnerId) {
            $q->where('partner_id', $partnerId);
        })->whereNotIn('status', ['cancelled']);

        $query->where(function($q) use ($date) {
            $q->whereDate('created_at', $date)
              ->orWhereDate('completed_at', $date)
              ->orWhereDate('failed_at', $date)
              ->orWhereDate('updated_at', $date)
              ->orWhereDate('requested_at', $date)
              ->orWhereDate('cancelled_at', $date);
        });

        $orders = $query->get();
        \Log::info('Partner Activity Found', ['count' => $orders->count()]);

        return $this->formatActivityResponse($orders);
    }

    /**
     * Get daily delivery activity for a specific dispatcher.
     */
    public function dispatcherDaily(Request $request)
    {
        try {
            $user = auth()->user();
            if (!$user || !$user->hasAnyRole(['super_admin', 'operations_manager', 'operations', 'accountant'])) {
                return $this->error('Access denied', 403);
            }

            \Log::info('Dispatcher Activity Request', [
                'dispatcher_id' => $request->dispatcher_id,
                'date' => $request->date,
                'user' => $user->id
            ]);

            $request->validate([
                'dispatcher_id' => 'required|exists:dispatchers,id',
                'date' => 'nullable|date',
            ]);

            $date = $request->date ? Carbon::parse($request->date) : Carbon::today();
            $dispatcherId = $request->dispatcher_id;

            $query = FulfillmentRequest::with([
                'partnerCustomer.partner',
                'partnerProduct',
                'dispatcher.user',
            ])->where('dispatcher_id', $dispatcherId)
          ->whereNotIn('status', ['cancelled']);

            $query->where(function($q) use ($date) {
                $q->whereDate('created_at', $date)
                  ->orWhereDate('completed_at', $date)
                  ->orWhereDate('failed_at', $date)
                  ->orWhereDate('updated_at', $date)
                  ->orWhereDate('requested_at', $date)
                  ->orWhereDate('cancelled_at', $date);
            });

            $partnerOrders = $query->get();

            // Also fetch standard Pickups & Deliveries
            $pickupQuery = PickupDelivery::with(['shipment', 'dispatcher.user'])
                ->where('dispatcher_id', $dispatcherId);
            
            $pickupQuery->where(function($q) use ($date) {
                $q->whereDate('created_at', $date)
                  ->orWhereDate('scheduled_date', $date)
                  ->orWhereDate('actual_date', $date)
                  ->orWhereDate('updated_at', $date);
            });

            $standardTasks = $pickupQuery->get();

            $allActivity = $partnerOrders->concat($standardTasks);
            \Log::info('Dispatcher Activity Found', [
                'partner_orders_count' => $partnerOrders->count(),
                'standard_tasks_count' => $standardTasks->count(),
                'total' => $allActivity->count()
            ]);

            return $this->formatActivityResponse($allActivity);
        } catch (\Throwable $e) {
            \Log::error('Dispatcher Activity Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->error('Failed to fetch dispatcher activity: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Fleet-wide daily traction aggregated per dispatcher.
     * Used by the Operations analytics page to answer:
     *   - How many orders were given to each dispatcher today?
     *   - How many were delivered?
     *   - How many products/orders are still in their care (not yet delivered / failed)?
     */
    public function dispatcherTraction(Request $request)
    {
        try {
            $user = auth()->user();
            if (!$user || !$user->hasAnyRole(['super_admin', 'operations_manager', 'operations', 'accountant'])) {
                return $this->error('Access denied', 403);
            }

            $date = $request->date ? Carbon::parse($request->date) : Carbon::today();
            $startOfDay = $date->copy()->startOfDay();
            $endOfDay = $date->copy()->endOfDay();

            $dispatchers = Dispatcher::with(['user', 'vehicle'])->get();

            // --- Pull all fulfillment requests touching a dispatcher on the given date ---
            $fulfillmentRows = FulfillmentRequest::with(['partnerCustomer.customer', 'partnerProduct'])
                ->whereNotIn('status', ['cancelled'])
                ->where(function ($q) use ($date) {
                    $q->whereDate('created_at', $date)
                      ->orWhereDate('completed_at', $date)
                      ->orWhereDate('failed_at', $date)
                      ->orWhereDate('updated_at', $date)
                      ->orWhereDate('requested_at', $date);
                })
                ->whereNotNull('dispatcher_id')
                ->get();

            // --- Pull all pickup_deliveries for the same day ---
            $pickupRows = PickupDelivery::with(['shipment'])
                ->where(function ($q) use ($date) {
                    $q->whereDate('created_at', $date)
                      ->orWhereDate('scheduled_date', $date)
                      ->orWhereDate('actual_date', $date)
                      ->orWhereDate('updated_at', $date);
                })
                ->whereNotNull('dispatcher_id')
                ->get();

            // Group by dispatcher_id
            $byDispatcher = [];

            foreach ($fulfillmentRows as $row) {
                $id = (int) $row->dispatcher_id;
                $byDispatcher[$id]['fulfillment'][] = $row;
            }

            foreach ($pickupRows as $row) {
                $id = (int) $row->dispatcher_id;
                $byDispatcher[$id]['pickup'][] = $row;
            }

            $traction = $dispatchers->map(function ($dispatcher) use ($byDispatcher, $date) {
                $id = $dispatcher->id;
                $buckets = $byDispatcher[$id] ?? ['fulfillment' => [], 'pickup' => []];

                $all = collect($buckets['fulfillment'])->merge($buckets['pickup']);

                $total = $all->count();
                $delivered = $all->filter(fn ($o) => in_array(strtolower($o->status ?? ''), ['delivered', 'completed']))->count();
                $failed = $all->filter(fn ($o) => in_array(strtolower($o->status ?? ''), ['failed', 'cancelled', 'rejected', 'unsuccessful', 'awaiting_reschedule']))->count();
                $inTransit = $all->filter(fn ($o) => in_array(strtolower($o->status ?? ''), ['in_transit', 'out_for_delivery', 'picked_up', 'ready_for_pickup']))->count();
                $inProgress = $all->filter(fn ($o) => in_array(strtolower($o->status ?? ''), ['assigned', 'in_progress', 'picking', 'packing', 'shipping']))->count();
                $pending = $all->filter(fn ($o) => in_array(strtolower($o->status ?? ''), ['pending', 'acknowledged', 'awaiting_partner_action', 'accepted', 'scheduled']))->count();

                // "In care" = anything assigned to this dispatcher that hasn't been
                // delivered or failed yet.  These are items physically with them.
                $inCare = $inTransit + $inProgress + $pending;

                // Total product units still with them (only fulfillment requests carry quantity).
                $inCareQty = collect($buckets['fulfillment'])
                    ->filter(fn ($o) => in_array(strtolower($o->status ?? ''), [
                        'in_transit', 'out_for_delivery', 'picked_up', 'ready_for_pickup',
                        'assigned', 'in_progress', 'picking', 'packing', 'shipping',
                        'pending', 'acknowledged', 'awaiting_partner_action', 'accepted', 'scheduled',
                    ]))
                    ->sum(fn ($o) => (int) ($o->quantity ?? 0));

                $completionRate = $total > 0 ? (int) round(($delivered / $total) * 100) : 0;

                return [
                    'dispatcher_id' => $id,
                    'name' => $dispatcher->user->name ?? 'Dispatcher',
                    'avatar' => strtoupper(substr($dispatcher->user->name ?? 'D', 0, 2)),
                    'vehicle' => $dispatcher->vehicle ? [
                        'id' => $dispatcher->vehicle->id,
                        'plate_number' => $dispatcher->vehicle->plate_number ?? null,
                        'name' => $dispatcher->vehicle->name ?? null,
                    ] : null,
                    'is_available' => (bool) $dispatcher->is_available,
                    'totals' => [
                        'given' => $total,
                        'delivered' => $delivered,
                        'failed' => $failed,
                        'in_transit' => $inTransit,
                        'in_progress' => $inProgress,
                        'pending' => $pending,
                    ],
                    'in_care' => [
                        'orders' => $inCare,
                        'quantity' => (int) $inCareQty,
                    ],
                    'completion_rate' => $completionRate,
                    'has_activity' => $total > 0,
                ];
            })
            ->sortByDesc(fn ($d) => [$d['in_care']['orders'], $d['totals']['given']])
            ->values()
            ->all();

            $fleet = [
                'active_dispatchers' => collect($traction)->where('has_activity', true)->count(),
                'total_in_care_orders' => collect($traction)->sum(fn ($d) => $d['in_care']['orders']),
                'total_in_care_quantity' => collect($traction)->sum(fn ($d) => $d['in_care']['quantity']),
                'total_given' => collect($traction)->sum(fn ($d) => $d['totals']['given']),
                'total_delivered' => collect($traction)->sum(fn ($d) => $d['totals']['delivered']),
            ];

            return $this->success([
                'date' => $date->toDateString(),
                'fleet' => $fleet,
                'dispatchers' => $traction,
            ]);
        } catch (\Throwable $e) {
            \Log::error('Dispatcher Traction Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->error('Failed to fetch dispatcher traction: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Items currently in a specific dispatcher's care (not delivered, not failed).
     */
    public function dispatcherInCare(Request $request, int $id)
    {
        try {
            $user = auth()->user();
            if (!$user || !$user->hasAnyRole(['super_admin', 'operations_manager', 'operations', 'accountant'])) {
                return $this->error('Access denied', 403);
            }

            $date = $request->date ? Carbon::parse($request->date) : Carbon::today();

            $dispatcher = Dispatcher::with(['user', 'vehicle'])->findOrFail($id);

            $activeStatuses = [
                'in_transit', 'out_for_delivery', 'picked_up', 'ready_for_pickup',
                'assigned', 'in_progress', 'picking', 'packing', 'shipping',
                'pending', 'acknowledged', 'awaiting_partner_action', 'accepted', 'scheduled',
            ];

            $fulfillment = FulfillmentRequest::with(['partnerCustomer.customer', 'partnerProduct'])
                ->where('dispatcher_id', $id)
                ->whereNotIn('status', ['cancelled', 'delivered', 'completed', 'failed', 'rejected'])
                ->whereIn('status', $activeStatuses)
                ->where(function ($q) use ($date) {
                    $q->whereDate('created_at', $date)
                      ->orWhereDate('updated_at', $date)
                      ->orWhereDate('requested_at', $date);
                })
                ->get();

            $pickup = PickupDelivery::with(['shipment'])
                ->where('dispatcher_id', $id)
                ->whereNotIn('status', ['cancelled', 'completed', 'failed'])
                ->whereIn('status', $activeStatuses)
                ->where(function ($q) use ($date) {
                    $q->whereDate('created_at', $date)
                      ->orWhereDate('scheduled_date', $date)
                      ->orWhereDate('updated_at', $date);
                })
                ->get();

            $items = $fulfillment->map(function ($o) {
                $pc = $o->partnerCustomer;
                return [
                    'id' => $o->id,
                    'kind' => 'fulfillment',
                    'request_number' => $o->request_number ?? ('REQ-' . str_pad((string) $o->id, 5, '0', STR_PAD_LEFT)),
                    'customer_name' => $o->delivery_notes ?: ($pc?->customer?->name ?? '—'),
                    'product_name' => $o->partnerProduct?->name ?? '—',
                    'quantity' => (int) ($o->quantity ?? 0),
                    'status' => $o->status,
                    'delivery_address' => $o->delivery_address ?? '—',
                    'updated_at' => optional($o->updated_at)->toIso8601String(),
                ];
            })->merge($pickup->map(function ($o) {
                return [
                    'id' => $o->id,
                    'kind' => 'pickup_delivery',
                    'request_number' => $o->shipment?->tracking_number ?? 'PD-' . $o->id,
                    'customer_name' => $o->type === 'pickup'
                        ? ($o->shipment?->sender_name ?? '—')
                        : ($o->shipment?->receiver_name ?? '—'),
                    'product_name' => $o->shipment?->package_type ?? 'Package',
                    'quantity' => 1,
                    'status' => $o->status,
                    'delivery_address' => $o->delivery_address ?? $o->pickup_address ?? '—',
                    'updated_at' => optional($o->updated_at)->toIso8601String(),
                ];
            }))->values();

            return $this->success([
                'date' => $date->toDateString(),
                'dispatcher' => [
                    'id' => $dispatcher->id,
                    'name' => $dispatcher->user->name ?? 'Dispatcher',
                    'vehicle' => $dispatcher->vehicle ? [
                        'plate_number' => $dispatcher->vehicle->plate_number ?? null,
                        'name' => $dispatcher->vehicle->name ?? null,
                    ] : null,
                ],
                'in_care' => [
                    'orders' => $items->count(),
                    'quantity' => (int) $items->sum('quantity'),
                ],
                'items' => $items,
            ]);
        } catch (\Throwable $e) {
            \Log::error('Dispatcher In-Care Error', [
                'dispatcher_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return $this->error('Failed to fetch dispatcher in-care items: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Format the orders into the requested categories.
     */
    private function formatActivityResponse($orders)
    {
        try {
            // Normalize statuses for matching
            foreach ($orders as $order) {
                $order->status_norm = strtolower($order->status ?? '');
            }

            $successful = $orders->whereIn('status_norm', ['delivered', 'completed', 'success']);
            
            // In Transit: dispatcher is on the road
            $inTransit = $orders->whereIn('status_norm', ['in_transit', 'out_for_delivery', 'picked_up', 'ready_for_pickup']);
            
            // In Progress: internal business state (packing, contacting customer, assigned but not moving)
            $inProgress = $orders->whereIn('status_norm', ['assigned', 'in_progress', 'picking', 'packing', 'shipping']);
            
            // Pending: strictly pending (plus acknowledged/accepted but not yet assigned)
            $pending = $orders->whereIn('status_norm', ['pending', 'acknowledged', 'awaiting_partner_action', 'accepted', 'scheduled']);
            
            // Failed: failed, cancelled, rejected, awaiting_reschedule
            $failed = $orders->whereIn('status_norm', ['failed', 'cancelled', 'rejected', 'unsuccessful', 'awaiting_reschedule']);

            // Catch-all for any order not categorized
            // Build a list of all categorized instances to find uncategorized ones
            $categorized = $successful->concat($inTransit)
                ->concat($inProgress)
                ->concat($pending)
                ->concat($failed);

            // Any orders not explicitly categorized go to pending
            // Use model comparison to avoid ID collisions between different model types
            $uncategorized = $orders->filter(function($order) use ($categorized) {
                return !$categorized->contains(function($c) use ($order) {
                    return $c->id === $order->id && get_class($c) === get_class($order);
                });
            });

            if ($uncategorized->count() > 0) {
                $pending = $pending->concat($uncategorized);
            }

            return $this->success([
                'summary' => [
                    'total' => $orders->count(),
                    'successful' => $successful->count(),
                    'in_transit' => $inTransit->count(),
                    'in_progress' => $inProgress->count(),
                    'pending' => $pending->count(),
                    'failed' => $failed->count(),
                ],
                'details' => [
                    'successful' => $this->mapOrders($successful),
                    'in_transit' => $this->mapOrders($inTransit),
                    'in_progress' => $this->mapOrders($inProgress),
                    'pending' => $this->mapOrders($pending),
                    'failed' => $this->mapOrders($failed),
                ],
                'raw_orders_count' => $orders->count()
            ]);
        } catch (\Throwable $e) {
            \Log::error('Format Activity Response Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->success([
                'summary' => [
                    'total' => 0,
                    'successful' => 0,
                    'in_transit' => 0,
                    'in_progress' => 0,
                    'pending' => 0,
                    'failed' => 0,
                ],
                'details' => [
                    'successful' => [],
                    'in_transit' => [],
                    'in_progress' => [],
                    'pending' => [],
                    'failed' => [],
                ],
            ]);
        }
    }

    private function mapOrders($orders)
    {
        try {
            return $orders->map(function ($order) {
                $isStandard = $order instanceof \App\Models\PickupDelivery;
                $pc = $isStandard ? null : $order->partnerCustomer;
                $customerName = 'N/A';
                
                // Logic for customer name:
                // 1. If standard shipment, use sender/receiver name
                // 2. If fulfillment request, use delivery_notes (where recipient is stored) or shipment receiver
                // 3. Fallback to PartnerCustomer customer_name
                // 4. Fallback to Customer model name
                $customerName = 'N/A';
                if ($isStandard) {
                    $customerName = $order->type === 'pickup' 
                        ? ($order->shipment?->sender_name ?: 'N/A') 
                        : ($order->shipment?->receiver_name ?: 'N/A');
                } else {
                    // Try fulfillment-specific sources
                    $customerName = $order->delivery_notes ?: ($order->shipment?->receiver_name ?: null);
                    
                    if (!$customerName && $pc) {
                        $customerName = $pc->customer_name ?: ($pc->customer?->name ?: 'N/A');
                    }
                }

                return [
                    'id' => $order->id,
                    'request_number' => $isStandard ? ($order->shipment?->tracking_number ?: 'N/A') : ($order->request_number ?? 'N/A'),
                    'customer_name' => $customerName,
                    'product_name' => $isStandard ? ($order->shipment?->package_type ?: 'Package') : ($order->partnerProduct?->name ?: 'N/A'),
                    'delivery_address' => $isStandard ? ($order->shipment?->receiver_address ?: 'N/A') : ($order->delivery_address ?? 'N/A'),
                    'status' => $order->status ?? 'unknown',
                    'dispatcher_name' => ($order->dispatcher?->user?->name ?: 'Unassigned'),
                    'is_standard_shipment' => $isStandard,
                    'fail_reason' => $isStandard ? ($order->failure_reason ?? null) : ($order->fail_reason ?? null),
                    'delay_reason' => $isStandard ? null : ($order->delay_reason ?? null),
                    'cancel_reason' => $isStandard ? null : ($order->cancel_reason ?? null),
                    'notes' => $isStandard ? ($order->notes ?: $order->completion_notes) : ($order->notes ?? null),
                    'requested_at' => $this->formatDate($isStandard ? $order->created_at : ($order->requested_at ?? null)),
                    'completed_at' => $this->formatDate($isStandard ? $order->actual_date : ($order->completed_at ?? null)),
                    'failed_at' => $this->formatDate($isStandard ? ($order->status === 'failed' ? $order->updated_at : null) : ($order->failed_at ?? null)),
                    'updated_at' => $this->formatDate($order->updated_at),
                    'cod_amount' => $isStandard ? ($order->shipment?->cod_amount ?? 0) : ($order->cod_amount ?? 0),
                    'amount_collected' => $isStandard ? ($order->shipment?->amount_collected ?? 0) : ($order->amount_collected ?? 0),
                    'delivery_cost' => $isStandard ? ($order->shipment?->delivery_fee ?? 0) : ($order->delivery_cost ?? 0),
                ];
            })->values();
        } catch (\Throwable $e) {
            \Log::error('Map Orders Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    private function formatDate($date)
    {
        if (!$date) return null;
        if ($date instanceof \Carbon\Carbon) return $date->toIso8601String();
        if (is_string($date)) return $date;
        return (string) $date;
    }
}
