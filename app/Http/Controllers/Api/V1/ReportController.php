<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Dispatcher;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Shipment;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function shipments(Request $request)
    {
        $query = Shipment::query();

        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $byStatus = $query->clone()
            ->select('status')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $byType = $query->clone()
            ->select('shipment_type')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('shipment_type')
            ->pluck('count', 'shipment_type')
            ->toArray();

        $total = $query->count();

        return $this->success([
            'total' => $total,
            'by_status' => $byStatus,
            'by_type' => $byType,
        ]);
    }

    public function revenue(Request $request)
    {
        $query = Invoice::where('status', 'paid');

        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $totalRevenue = $query->sum('total_amount');

        $byPaymentMethod = Payment::whereHas('invoice', function ($q) use ($request) {
            if ($request->has('date_from')) {
                $q->whereDate('created_at', '>=', $request->date_from);
            }
            if ($request->has('date_to')) {
                $q->whereDate('created_at', '<=', $request->date_to);
            }
        })
            ->select('payment_method')
            ->selectRaw('SUM(amount) as total')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method')
            ->toArray();

        return $this->success([
            'total_revenue' => $totalRevenue,
            'by_payment_method' => $byPaymentMethod,
        ]);
    }

    public function expenses(Request $request)
    {
        $query = Expense::query();

        if ($request->has('date_from')) {
            $query->whereDate('expense_date', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('expense_date', '<=', $request->date_to);
        }

        $totalExpenses = $query->sum('amount');

        $byCategory = $query->clone()
            ->select('category')
            ->selectRaw('SUM(amount) as total')
            ->groupBy('category')
            ->pluck('total', 'category')
            ->toArray();

        return $this->success([
            'total_expenses' => $totalExpenses,
            'by_category' => $byCategory,
        ]);
    }

    public function profit(Request $request)
    {
        $revenueQuery = Invoice::where('status', 'paid');
        $expenseQuery = Expense::query();

        if ($request->has('date_from')) {
            $revenueQuery->whereDate('created_at', '>=', $request->date_from);
            $expenseQuery->whereDate('expense_date', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $revenueQuery->whereDate('created_at', '<=', $request->date_to);
            $expenseQuery->whereDate('expense_date', '<=', $request->date_to);
        }

        $revenue = $revenueQuery->sum('total_amount');
        $expenses = $expenseQuery->sum('amount');
        $profit = $revenue - $expenses;

        return $this->success([
            'revenue' => $revenue,
            'expenses' => $expenses,
            'profit' => $profit,
            'profit_margin' => $revenue > 0 ? ($profit / $revenue) * 100 : 0,
        ]);
    }

    public function dispatchers(Request $request)
    {
        $dispatchers = Dispatcher::with('user')->get()->map(function ($dispatcher) {
            return [
                'id' => $dispatcher->id,
                'name' => $dispatcher->user->name,
                'total_deliveries' => $dispatcher->total_deliveries,
                'successful_deliveries' => $dispatcher->successful_deliveries,
                'failed_deliveries' => $dispatcher->total_deliveries - $dispatcher->successful_deliveries,
                'success_rate' => $dispatcher->success_rate,
            ];
        });

        return $this->success($dispatchers);
    }

    public function deliverySuccess()
    {
        $total = Shipment::whereIn('status', ['delivered', 'failed'])->count();
        $delivered = Shipment::where('status', 'delivered')->count();
        $failed = Shipment::where('status', 'failed')->count();

        return $this->success([
            'total' => $total,
            'delivered' => $delivered,
            'failed' => $failed,
            'success_rate' => $total > 0 ? ($delivered / $total) * 100 : 0,
            'failure_rate' => $total > 0 ? ($failed / $total) * 100 : 0,
        ]);
    }

    public function export(Request $request, string $type)
    {
        $headers = [];
        $data = [];

        switch ($type) {
            case 'shipments':
                $headers = ['Tracking Number', 'Customer', 'Status', 'Type', 'Created At'];
                $data = Shipment::with('customer')->get()->map(function ($s) {
                    return [
                        $s->tracking_number,
                        $s->customer?->name,
                        $s->status,
                        $s->shipment_type,
                        $s->created_at->toDateString(),
                    ];
                });
                break;

            case 'revenue':
                $headers = ['Invoice Number', 'Customer', 'Amount', 'Status', 'Date'];
                $data = Invoice::with('customer')->get()->map(function ($inv) {
                    return [
                        $inv->invoice_number,
                        $inv->customer?->name,
                        $inv->total_amount,
                        $inv->status,
                        $inv->created_at->toDateString(),
                    ];
                });
                break;

            case 'expenses':
                $headers = ['Title', 'Category', 'Amount', 'Date'];
                $data = Expense::all()->map(function ($exp) {
                    return [
                        $exp->title,
                        $exp->category,
                        $exp->amount,
                        $exp->expense_date,
                    ];
                });
                break;

            default:
                return $this->error('Invalid export type', 400);
        }

        return $this->success([
            'headers' => $headers,
            'data' => $data,
        ]);
    }
}
