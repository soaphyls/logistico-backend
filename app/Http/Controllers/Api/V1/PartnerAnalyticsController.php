<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Traits\PartnerModuleTrait;
use App\Models\PartnerCustomer;
use App\Models\PartnerProduct;
use App\Models\FulfillmentRequest;
use App\Models\Dispatcher;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PartnerAnalyticsController extends Controller
{
    use PartnerModuleTrait;

    public function analytics(Request $request)
    {
        try {
            $this->checkModuleEnabled();
        } catch (\Exception $e) {
            return $this->success([
                'stats' => [
                    'total_revenue' => 0,
                    'total_orders' => 0,
                    'pending_orders' => 0,
                    'total_customers' => 0,
                    'active_customers' => 0,
                    'products' => 0,
                    'completion_rate' => 0,
                ],
                'orders_by_status' => [],
                'top_customers' => [],
                'am_performance' => [],
                'monthly_revenue' => [],
                'top_products' => [],
            ]);
        }

        $days = max((int) ($request->days ?? 30), 1);
        $startDate = now()->subDays($days);

        $totalCustomers = PartnerCustomer::count();
        $activeCustomers = PartnerCustomer::where('is_active', true)->count();
        $totalProducts = PartnerProduct::count();

        $ordersQuery = FulfillmentRequest::query()->where('created_at', '>=', $startDate);
        $totalOrders = (clone $ordersQuery)->count();
        $pendingOrders = (clone $ordersQuery)->whereIn('status', ['pending', 'acknowledged', 'assigned', 'in_transit'])->count();
        $completedOrders = (clone $ordersQuery)->where('status', 'delivered')->count();
        $completionRate = $totalOrders > 0 ? round(($completedOrders / $totalOrders) * 100) : 0;
        $totalRevenue = (float) ((clone $ordersQuery)
            ->where('status', 'delivered')
            ->sum('delivery_cost') ?? 0);

        $ordersByStatus = FulfillmentRequest::select('status', DB::raw('count(*) as count'))
            ->where('created_at', '>=', $startDate)
            ->groupBy('status')
            ->get()
            ->pluck('count', 'status')
            ->toArray();

        $topCustomers = PartnerCustomer::query()
            ->leftJoin('fulfillment_requests', function ($join) use ($startDate) {
                $join->on('fulfillment_requests.partner_customer_id', '=', 'partner_customers.id')
                    ->where('fulfillment_requests.created_at', '>=', $startDate);
            })
            ->leftJoin('customers', 'customers.id', '=', 'partner_customers.customer_id')
            ->select(
                'partner_customers.id',
                DB::raw('COALESCE(customers.name, "-") as customer_name'),
                DB::raw('count(fulfillment_requests.id) as orders'),
                DB::raw('COALESCE(sum(CASE WHEN fulfillment_requests.status = "delivered" THEN fulfillment_requests.delivery_cost ELSE 0 END), 0) as revenue')
            )
            ->groupBy('partner_customers.id', 'customers.name')
            ->orderByDesc('revenue')
            ->orderByDesc('orders')
            ->limit(5)
            ->get()
            ->map(function ($row) {
                return [
                    'name' => $row->customer_name,
                    'orders' => (int) $row->orders,
                    'revenue' => (float) $row->revenue,
                ];
            });

        // Operations staff handling partner customers.
        $staffIds = User::whereHas('role', function ($query) {
            $query->whereIn('slug', ['operations', 'operations_manager', 'customer_service']);
        })->pluck('id');

        $staffUsers = User::whereIn('id', $staffIds)->with('role')->get()->keyBy('id');

        $customerCounts = PartnerCustomer::whereIn('staff_id', $staffIds)
            ->select('staff_id', DB::raw('count(*) as count'))
            ->groupBy('staff_id')
            ->pluck('count', 'staff_id')
            ->toArray();

        $orderStats = FulfillmentRequest::whereIn('staff_id', $staffIds)
            ->where('created_at', '>=', $startDate)
            ->select(
                'staff_id',
                DB::raw('count(*) as total_orders'),
                DB::raw('SUM(CASE WHEN status = "delivered" THEN 1 ELSE 0 END) as completed_orders'),
                DB::raw('SUM(CASE WHEN status = "delivered" THEN delivery_cost ELSE 0 END) as revenue')
            )
            ->groupBy('staff_id')
            ->get()
            ->keyBy('staff_id');

        $amPerformance = $staffIds->map(function ($staffId) use ($staffUsers, $customerCounts, $orderStats) {
            $user = $staffUsers[$staffId] ?? null;
            if (!$user) return null;

            $stats = $orderStats[$staffId] ?? null;
            $ordersCount = $stats ? (int) $stats->total_orders : 0;
            $completedCount = $stats ? (int) $stats->completed_orders : 0;
            $revenue = $stats ? (float) $stats->revenue : 0;

            return [
                'name' => $user->name,
                'role' => $user->role->slug ?? 'operations',
                'customers' => $customerCounts[$staffId] ?? 0,
                'orders' => $ordersCount,
                'revenue' => $revenue,
                'completion_rate' => $ordersCount > 0 ? round(($completedCount / $ordersCount) * 100) : 0,
            ];
        })->filter()->sortByDesc('orders')->values();

        $topProducts = PartnerProduct::query()
            ->leftJoin('fulfillment_requests', function ($join) use ($startDate) {
                $join->on('fulfillment_requests.partner_product_id', '=', 'partner_products.id')
                    ->where('fulfillment_requests.created_at', '>=', $startDate);
            })
            ->select(
                'partner_products.id',
                'partner_products.name',
                DB::raw('COALESCE(sum(fulfillment_requests.quantity), 0) as sold'),
                DB::raw('count(fulfillment_requests.id) as orders')
            )
            ->groupBy('partner_products.id', 'partner_products.name')
            ->orderByDesc('sold')
            ->orderByDesc('orders')
            ->limit(5)
            ->get()
            ->map(function ($row) {
                return [
                    'name' => $row->name ?? '-',
                    'sold' => (int) $row->sold,
                    'orders' => (int) $row->orders,
                ];
            });

        $monthlyRevenue = collect(range(0, 11))->map(function ($i) {
            $monthStart = now()->startOfMonth()->subMonths(11 - $i);
            $monthEnd = (clone $monthStart)->endOfMonth();

            $revenue = (float) FulfillmentRequest::query()
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->where('status', 'delivered')
                ->sum('delivery_cost');

            return [
                'month' => $monthStart->format('M'),
                'revenue' => $revenue,
            ];
        });

        return $this->success([
            'stats' => [
                'total_revenue' => $totalRevenue,
                'total_orders' => $totalOrders,
                'pending_orders' => $pendingOrders,
                'total_customers' => $totalCustomers,
                'active_customers' => $activeCustomers,
                'products' => $totalProducts,
                'completion_rate' => $completionRate,
            ],
            'orders_by_status' => [
                ['name' => 'Pending', 'value' => $ordersByStatus['pending'] ?? 0, 'color' => '#fbbf24'],
                ['name' => 'Processing', 'value' => $ordersByStatus['processing'] ?? 0, 'color' => '#3b82f6'],
                ['name' => 'Acknowledged', 'value' => $ordersByStatus['acknowledged'] ?? 0, 'color' => '#8b5cf6'],
                ['name' => 'In Transit', 'value' => $ordersByStatus['in_transit'] ?? 0, 'color' => '#6366f1'],
                ['name' => 'Delivered', 'value' => $ordersByStatus['delivered'] ?? 0, 'color' => '#22c55e'],
                ['name' => 'Cancelled', 'value' => $ordersByStatus['cancelled'] ?? 0, 'color' => '#ef4444'],
            ],
            'top_customers' => $topCustomers,
            'am_performance' => $amPerformance,
            'monthly_revenue' => $monthlyRevenue,
            'top_products' => $topProducts,
        ]);
    }

    public function staffPerformance(Request $request)
    {
        $period = $request->get('period', 'month');

        $dateRange = $this->getDateRange($period);

        $dispatchers = Dispatcher::where('is_active', true)->get();
        $warehouseStaff = User::whereHas('roles', function ($q) {
            $q->whereIn('slug', ['warehouse_officer', 'warehouse_manager', 'warehouse_staff']);
        })->get();

        $pdStats = DB::table('pickup_deliveries')
            ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->select(
                'dispatcher_id',
                'type',
                DB::raw('count(*) as total'),
                DB::raw('SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed'),
                DB::raw('SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed')
            )
            ->whereNotNull('dispatcher_id')
            ->groupBy('dispatcher_id', 'type')
            ->get();

        $dispatcherMap = [];
        foreach ($pdStats as $stat) {
            $dispatcherMap[$stat->dispatcher_id][$stat->type] = $stat;
        }

        $dispatcherStats = $dispatchers->map(function ($dispatcher) use ($dispatcherMap) {
            $pickupStat = $dispatcherMap[$dispatcher->id]['pickup'] ?? null;
            $deliveryStat = $dispatcherMap[$dispatcher->id]['delivery'] ?? null;

            $totalPickups = $pickupStat ? $pickupStat->total : 0;
            $totalDeliveries = $deliveryStat ? $deliveryStat->total : 0;
            
            $completedPickups = $pickupStat ? $pickupStat->completed : 0;
            $completedDeliveries = $deliveryStat ? $deliveryStat->completed : 0;
            
            $failedPickups = $pickupStat ? $pickupStat->failed : 0;
            $failedDeliveries = $deliveryStat ? $deliveryStat->failed : 0;

            $total = $totalPickups + $totalDeliveries;
            $completed = $completedPickups + $completedDeliveries;
            $failed = $failedPickups + $failedDeliveries;

            return [
                'id' => $dispatcher->id,
                'name' => $dispatcher->user->name ?? $dispatcher->name,
                'role' => 'Dispatcher',
                'avatar' => substr($dispatcher->user->name ?? $dispatcher->name, 0, 2),
                'vehicle' => $dispatcher->vehicle->name ?? null,
                'total' => $total,
                'completed' => $completed,
                'failed' => $failed,
                'on_time_rate' => $total > 0 ? round(($completed / $total) * 100) : 0,
                'rating' => 4.5,
            ];
        });

        $whStats = DB::table('pickup_deliveries')
            ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->where('type', 'pickup')
            ->select(
                'created_by',
                DB::raw('count(*) as total'),
                DB::raw('SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed'),
                DB::raw('SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed')
            )
            ->groupBy('created_by')
            ->get()
            ->keyBy('created_by');

        $warehouseStats = $warehouseStaff->map(function ($staff) use ($whStats) {
            $stat = $whStats[$staff->id] ?? null;
            
            $total = $stat ? $stat->total : 0;
            $completed = $stat ? $stat->completed : 0;
            $failed = $stat ? $stat->failed : 0;

            return [
                'id' => $staff->id,
                'name' => $staff->name,
                'role' => 'Warehouse Officer',
                'avatar' => substr($staff->name, 0, 2),
                'total' => $total,
                'completed' => $completed,
                'failed' => $failed,
                'on_time_rate' => $total > 0 ? round(($completed / $total) * 100) : 0,
                'rating' => 4.7,
            ];
        });

        $allStaff = $dispatcherStats->concat($warehouseStats);

        $totalDispatchers = $dispatcherStats->count();
        $totalWarehouse = $warehouseStats->count();
        $avgRating = $allStaff->count() > 0 ? round($allStaff->avg('rating'), 1) : 0;
        $avgOnTime = $allStaff->count() > 0 ? round($allStaff->avg('on_time_rate')) : 0;

        return $this->success([
            'overview' => [
                'total_dispatchers' => $totalDispatchers,
                'total_warehouse' => $totalWarehouse,
                'avg_rating' => $avgRating,
                'avg_on_time' => $avgOnTime,
            ],
            'staff' => $allStaff->values(),
        ]);
    }

    private function getDateRange(string $period): array
    {
        $now = now();
        switch ($period) {
            case 'day':
                return ['start' => $now->startOfDay(), 'end' => $now->endOfDay()];
            case 'week':
                return ['start' => $now->startOfWeek(), 'end' => $now->endOfWeek()];
            case 'month':
                return ['start' => $now->startOfMonth(), 'end' => $now->endOfMonth()];
            case 'quarter':
                return ['start' => $now->startOfQuarter(), 'end' => $now->endOfQuarter()];
            case '6month':
                return ['start' => $now->subMonths(6)->startOfDay(), 'end' => $now->endOfDay()];
            case 'year':
                return ['start' => $now->startOfYear(), 'end' => $now->endOfYear()];
            default:
                return ['start' => $now->startOfMonth(), 'end' => $now->endOfMonth()];
        }
    }
}
