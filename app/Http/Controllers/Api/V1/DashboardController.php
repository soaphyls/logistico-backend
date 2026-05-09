<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Inventory;
use App\Models\Shipment;
use App\Models\Expense;
use App\Models\Dispatcher;
use App\Models\PickupDelivery;
use App\Models\PartnerProduct;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function stats(Request $request)
    {
        $user = $request->user();
        $role = $user->role?->slug ?? null;

        $today = now()->startOfDay();
        $weekStart = now()->startOfWeek();
        $monthStart = now()->startOfMonth();

        if (in_array($role, ['super_admin', 'operations_manager', 'operations'])) {
            return $this->fullStats($monthStart, $today);
        }

        if ($role === 'accountant') {
            return $this->accountantStats($monthStart);
        }

        if ($role === 'driver') {
            return $this->dispatcherStats($user, $today);
        }

        if ($role === 'warehouse_officer') {
            return $this->warehouseOfficerStats($user);
        }

        if ($role === 'customer_service') {
            return $this->customerServiceStats($monthStart, $today);
        }

        return $this->fullStats($monthStart, $today);
    }

    private function fullStats($monthStart, $today)
    {
        $totalShipmentsToday = Shipment::whereDate('created_at', $today)->count();
        $totalShipmentsWeek = Shipment::whereDate('created_at', '>=', now()->startOfWeek())->count();
        $totalShipmentsMonth = Shipment::whereDate('created_at', '>=', $monthStart)->count();
        $totalShipmentsAll = Shipment::count();

        $shipmentsByStatus = Shipment::select('status')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $pendingDeliveries = Shipment::whereIn('status', ['pending', 'picked_up', 'in_transit', 'out_for_delivery'])->count();
        $inTransit = Shipment::whereIn('status', ['in_transit', 'out_for_delivery'])->count();
        $deliveredToday = Shipment::where('status', 'delivered')
            ->whereDate('actual_delivery_date', $today)
            ->count();

        $revenueThisMonth = Invoice::where('status', 'paid')
            ->whereDate('created_at', '>=', $monthStart)
            ->sum('total_amount');

        $expensesThisMonth = Expense::whereDate('expense_date', '>=', $monthStart)
            ->sum('amount');

        $profitThisMonth = $revenueThisMonth - $expensesThisMonth;

        // Daily shipments for the last 7 days (oldest to newest)
        $dailyShipments = collect(range(6, 0))->map(function ($daysAgo) {
            $date = now()->subDays($daysAgo)->toDateString();
            $count = Shipment::whereDate('created_at', $date)->count();
            return [
                'date' => $date,
                'day' => now()->subDays($daysAgo)->format('M d'),
                'count' => $count,
            ];
        });

        // Monthly revenue & expenses for the last 6 months (oldest to newest)
        $monthlyFinancials = collect(range(5, 0))->map(function ($monthsAgo) {
            $startOfMonth = now()->subMonths($monthsAgo)->startOfMonth();
            $endOfMonth = now()->subMonths($monthsAgo)->endOfMonth();
            
            $revenue = Invoice::where('status', 'paid')
                ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                ->sum('total_amount');

            $expenses = Expense::whereBetween('expense_date', [$startOfMonth, $endOfMonth])
                ->sum('amount');

            return [
                'month' => $startOfMonth->format('M'),
                'revenue' => $revenue,
                'expenses' => $expenses,
            ];
        });

        $recentActivities = ActivityLog::with('user')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'action' => $log->action,
                    'description' => $log->description,
                    'user_name' => $log->user?->name,
                    'created_at' => $log->created_at->toIso8601String(),
                ];
            });

        $recentShipments = Shipment::with('customer')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($shipment) {
                return [
                    'id' => $shipment->id,
                    'tracking_number' => $shipment->tracking_number,
                    'sender_name' => $shipment->sender_name,
                    'receiver_name' => $shipment->receiver_name,
                    'customer_name' => $shipment->customer?->name,
                    'shipment_type' => $shipment->shipment_type,
                    'status' => $shipment->status,
                    'created_at' => $shipment->created_at->toIso8601String(),
                ];
            });

        $lowStockAlerts = Inventory::whereRaw('quantity <= reorder_level')
            ->with('warehouse')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'item_name' => $item->item_name,
                    'warehouse_name' => $item->warehouse?->name,
                    'quantity' => $item->quantity,
                    'reorder_level' => $item->reorder_level,
                ];
            });

        $partnerLowStock = PartnerProduct::whereRaw('quantity <= reorder_level')
            ->with('partnerCustomer.customer')
            ->orderBy('quantity', 'asc')
            ->get()
            ->map(function ($product) {
                return [
                    'id' => $product->id,
                    'item_name' => $product->name,
                    'sku' => $product->sku,
                    'customer_name' => $product->partnerCustomer?->customer?->name ?? 'Unknown',
                    'quantity' => $product->quantity,
                    'reorder_level' => $product->reorder_level,
                ];
            });

        return $this->success([
            'total_shipments' => [
                'today' => $totalShipmentsToday,
                'this_week' => $totalShipmentsWeek,
                'this_month' => $totalShipmentsMonth,
                'all_time' => $totalShipmentsAll,
            ],
            'shipments_by_status' => $shipmentsByStatus,
            'pending_deliveries' => $pendingDeliveries,
            'in_transit' => $inTransit,
            'delivered_today' => $deliveredToday,
            'revenue_this_month' => $revenueThisMonth,
            'expenses_this_month' => $expensesThisMonth,
            'profit_this_month' => $profitThisMonth,
            'daily_shipments' => $dailyShipments,
            'monthly_financials' => $monthlyFinancials,
            'recent_activities' => $recentActivities,
            'recent_shipments' => $recentShipments,
            'low_stock_alerts' => $lowStockAlerts,
            'partner_low_stock' => $partnerLowStock,
        ]);
    }

    private function accountantStats($monthStart)
    {
        $revenueThisMonth = Invoice::where('status', 'paid')
            ->whereDate('created_at', '>=', $monthStart)
            ->sum('total_amount');

        $expensesThisMonth = Expense::whereDate('expense_date', '>=', $monthStart)
            ->sum('amount');

        $profitThisMonth = $revenueThisMonth - $expensesThisMonth;

        $recentActivities = ActivityLog::whereIn('action', ['payment_received', 'expense_created', 'invoice_created'])
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'action' => $log->action,
                    'description' => $log->description,
                    'user_name' => $log->user?->name,
                    'created_at' => $log->created_at->toIso8601String(),
                ];
            });

        return $this->success([
            'revenue_this_month' => $revenueThisMonth,
            'expenses_this_month' => $expensesThisMonth,
            'profit_this_month' => $profitThisMonth,
            'recent_activities' => $recentActivities,
        ]);
    }

    private function warehouseOfficerStats($user)
    {
        $warehouse = $user->managedWarehouse;

        if (!$warehouse) {
            return $this->success([
                'warehouse_shipments' => [],
                'warehouse_name' => null,
                'total_items_in_warehouse' => 0,
                'pending_dispatch' => 0,
                'low_stock_alerts' => [],
            ]);
        }

        $warehouseShipments = Shipment::where('warehouse_id', $warehouse->id)
            ->with('customer')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($shipment) {
                return [
                    'id' => $shipment->id,
                    'tracking_number' => $shipment->tracking_number,
                    'customer_name' => $shipment->customer?->name,
                    'shipment_type' => $shipment->shipment_type,
                    'status' => $shipment->status,
                    'shelf_position' => $shipment->shelf_position,
                    'created_at' => $shipment->created_at->toIso8601String(),
                ];
            });

        $totalItemsInWarehouse = Shipment::where('warehouse_id', $warehouse->id)
            ->whereIn('status', ['at_warehouse', 'in_transit'])
            ->count();

        $pendingDispatch = Shipment::where('warehouse_id', $warehouse->id)
            ->where('status', 'at_warehouse')
            ->count();

        $lowStockAlerts = Inventory::where('warehouse_id', $warehouse->id)
            ->whereRaw('quantity <= reorder_level')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'item_name' => $item->item_name,
                    'quantity' => $item->quantity,
                    'reorder_level' => $item->reorder_level,
                ];
            });

        return $this->success([
            'warehouse_shipments' => $warehouseShipments,
            'warehouse_name' => $warehouse->name,
            'total_items_in_warehouse' => $totalItemsInWarehouse,
            'pending_dispatch' => $pendingDispatch,
            'low_stock_alerts' => $lowStockAlerts,
        ]);
    }

    private function customerServiceStats($monthStart, $today)
    {
        $totalShipmentsToday = Shipment::whereDate('created_at', $today)->count();
        $totalShipmentsMonth = Shipment::whereDate('created_at', '>=', $monthStart)->count();

        $shipmentsByStatus = Shipment::select('status')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $pendingDeliveries = Shipment::whereIn('status', ['pending', 'picked_up', 'in_transit', 'out_for_delivery'])->count();
        $inTransit = Shipment::whereIn('status', ['in_transit', 'out_for_delivery'])->count();
        $deliveredToday = Shipment::where('status', 'delivered')
            ->whereDate('actual_delivery_date', $today)
            ->count();

        $recentActivities = ActivityLog::with('user')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'action' => $log->action,
                    'description' => $log->description,
                    'user_name' => $log->user?->name,
                    'created_at' => $log->created_at->toIso8601String(),
                ];
            });

        $recentShipments = Shipment::with('customer')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($shipment) {
                return [
                    'id' => $shipment->id,
                    'tracking_number' => $shipment->tracking_number,
                    'customer_name' => $shipment->customer?->name,
                    'shipment_type' => $shipment->shipment_type,
                    'status' => $shipment->status,
                    'created_at' => $shipment->created_at->toIso8601String(),
                ];
            });

        return $this->success([
            'total_shipments' => [
                'today' => $totalShipmentsToday,
                'this_month' => $totalShipmentsMonth,
            ],
            'shipments_by_status' => $shipmentsByStatus,
            'pending_deliveries' => $pendingDeliveries,
            'in_transit' => $inTransit,
            'delivered_today' => $deliveredToday,
            'recent_activities' => $recentActivities,
            'recent_shipments' => $recentShipments,
        ]);
    }

    private function dispatcherStats($user, $today)
    {
        $dispatcher = Dispatcher::where('user_id', $user->id)->first();

        if (!$dispatcher) {
            return $this->success([
                'my_deliveries' => [],
                'my_today_schedule' => [],
                'total_assigned' => 0,
                'completed_today' => 0,
                'pending_partner_orders' => 0
            ]);
        }

        // Standard Shipments
        $myDeliveries = Shipment::where('dispatcher_id', $dispatcher->id)
            ->with('customer')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($shipment) {
                return [
                    'id' => $shipment->id,
                    'type' => 'shipment',
                    'tracking_number' => $shipment->tracking_number,
                    'receiver_name' => $shipment->receiver_name,
                    'receiver_address' => $shipment->receiver_address,
                    'status' => $shipment->status,
                    'created_at' => $shipment->created_at->toIso8601String(),
                ];
            });

        // Partner Orders (Fulfillment Requests)
        $myPartnerOrders = \App\Models\FulfillmentRequest::where('dispatcher_id', $dispatcher->id)
            ->with('partnerCustomer.customer')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($order) {
                return [
                    'id' => $order->id,
                    'type' => 'partner_order',
                    'tracking_number' => $order->request_number,
                    'receiver_name' => $order->partnerCustomer?->customer?->name ?? 'Unknown',
                    'receiver_address' => $order->delivery_address,
                    'status' => $order->status,
                    'created_at' => $order->created_at->toIso8601String(),
                ];
            });

        // Merge for a unified view
        $mergedDeliveries = $myDeliveries->concat($myPartnerOrders)
            ->sortByDesc('created_at')
            ->values();

        $myTodaySchedule = PickupDelivery::where('dispatcher_id', $dispatcher->id)
            ->whereDate('scheduled_date', $today)
            ->with('shipment')
            ->orderBy('scheduled_date')
            ->get()
            ->map(function ($pd) {
                return [
                    'id' => $pd->id,
                    'type' => $pd->type,
                    'scheduled_date' => $pd->scheduled_date?->toIso8601String(),
                    'status' => $pd->status,
                    'shipment_tracking' => $pd->shipment?->tracking_number,
                ];
            });

        $totalAssigned = Shipment::where('dispatcher_id', $dispatcher->id)
            ->whereIn('status', ['pending', 'picked_up', 'in_transit', 'out_for_delivery'])
            ->count() + \App\Models\FulfillmentRequest::where('dispatcher_id', $dispatcher->id)
            ->whereIn('status', ['assigned', 'in_transit'])
            ->count();

        $completedToday = Shipment::where('dispatcher_id', $dispatcher->id)
            ->where('status', 'delivered')
            ->whereDate('actual_delivery_date', $today)
            ->count() + \App\Models\FulfillmentRequest::where('dispatcher_id', $dispatcher->id)
            ->where('status', 'delivered')
            ->whereDate('completed_at', $today)
            ->count();

        return $this->success([
            'my_deliveries' => $mergedDeliveries,
            'my_today_schedule' => $myTodaySchedule,
            'total_assigned' => $totalAssigned,
            'completed_today' => $completedToday,
        ]);
    }
}
