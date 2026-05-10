<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FulfillmentRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ReconciliationController extends Controller
{
    public function summary(Request $request)
    {
        $user = auth()->user();
        if (!$user || !$user->hasAnyRole(['super_admin', 'operations_manager', 'accountant'])) {
            return $this->error('Access denied', 403);
        }

        $query = FulfillmentRequest::with([
            'partnerCustomer.partner',
            'partnerProduct',
        ]);

        if ($request->partner_id) {
            $query->whereHas('partnerCustomer', function ($q) use ($request) {
                $q->where('partner_id', $request->partner_id);
            });
        }

        $status = $request->status;
        if ($status === 'successful') {
            $query->where('status', 'delivered');
        } elseif ($status === 'failed') {
            $query->where('status', 'failed');
        }

        if ($request->start_date) {
            $query->whereDate('completed_at', '>=', $request->start_date);
        }
        if ($request->end_date) {
            $query->whereDate('completed_at', '<=', $request->end_date);
        }

        $orders = $query->orderBy('completed_at', 'desc')->get();

        $totalOrders = $orders->count();
        $successfulOrders = $orders->where('status', 'delivered')->count();
        $failedOrders = $orders->where('status', 'failed')->count();
        $rejectedOrders = $orders->where('status', 'rejected')->count();
        
        // Logistics Revenue is the delivery costs of successful orders
        $revenue = $orders->where('status', 'delivered')->sum('delivery_cost');
        
        // Total COD Collected (Total price customer actually paid)
        // Falls back to cod_amount if amount_collected hasn't been set yet
        $totalCodCollected = $orders->where('status', 'delivered')->sum(function($o) {
            return $o->amount_collected ?? $o->cod_amount ?? 0;
        });
        
        // Net Remittance (Amount to pay partners: Actual COD - Delivery Fee)
        $netRemittance = $totalCodCollected - $revenue;

        return $this->success([
            'orders' => $orders->map(function ($order) {
                return [
                    'id' => $order->id,
                    'request_number' => $order->request_number,
                    'partner_name' => $order->partnerCustomer?->partner?->company
                        ?: $order->partnerCustomer?->partner?->name
                        ?: 'N/A',
                    'status' => $order->status,
                    'delivery_cost' => $order->delivery_cost,
                    'cod_amount' => $order->cod_amount,
                    'amount_collected' => $order->amount_collected,
                    'remittance_amount' => $order->remittance_amount,
                    'remittance_status' => $order->remittance_status ?: 'pending',
                    'payment_eligible' => $order->status === 'delivered',
                    'completed_at' => $order->completed_at?->toIso8601String(),
                ];
            }),
            'summary' => [
                'total_orders' => $totalOrders,
                'successful_orders' => $successfulOrders,
                'failed_orders' => $failedOrders,
                'rejected_orders' => $rejectedOrders,
                'total_cod_collected' => $totalCodCollected,
                'logistics_revenue' => $revenue,
                'net_remittance' => $netRemittance,
            ],
        ]);
    }

    public function partnerBreakdown(Request $request, $partnerId)
    {
        $user = auth()->user();
        if (!$user || !$user->hasAnyRole(['super_admin', 'operations_manager', 'accountant'])) {
            return $this->error('Access denied', 403);
        }

        $query = FulfillmentRequest::with(['partnerProduct'])->whereHas('partnerCustomer', function ($q) use ($partnerId) {
            $q->where('partner_id', $partnerId);
        });

        if ($request->start_date) {
            $query->whereDate('completed_at', '>=', $request->start_date);
        }
        if ($request->end_date) {
            $query->whereDate('completed_at', '<=', $request->end_date);
        }

        $orders = $query->get();

        $totalSuccessful = $orders->where('status', 'delivered')->count();
        $totalFailed = $orders->where('status', 'failed')->count();
        $totalRejected = $orders->where('status', 'rejected')->count();
        
        $totalCollected = $orders->where('status', 'delivered')->sum(function($o) {
            return $o->amount_collected ?? $o->cod_amount ?? 0;
        });
        $totalFees = $orders->where('status', 'delivered')->sum('delivery_cost');
        $totalRemittance = $totalCollected - $totalFees;

        // Average delivery time (in hours)
        $avgDeliveryTime = $orders->where('status', 'delivered')->whereNotNull('requested_at')->avg(function($order) {
            return $order->completed_at->diffInMinutes($order->requested_at);
        }) / 60 ?: 0;
        
        $avgDeliveryTime = abs($avgDeliveryTime);

        return $this->success([
            'partner_id' => $partnerId,
            'partner_name' => $orders->first()?->partnerCustomer?->partner?->company
                ?: $orders->first()?->partnerCustomer?->partner?->name
                ?: 'N/A',
            'bank_details' => [
                'bank_name' => $orders->first()?->partnerCustomer?->partner?->bank_name,
                'account_name' => $orders->first()?->partnerCustomer?->partner?->bank_account_name,
                'account_number' => $orders->first()?->partnerCustomer?->partner?->bank_account_number,
            ],
            'breakdown' => [
                'total_successful' => $totalSuccessful,
                'total_failed' => $totalFailed,
                'total_rejected' => $totalRejected,
                'total_collected' => $totalCollected,
                'total_fees' => $totalFees,
                'total_payable' => $totalRemittance,
                'success_rate' => round($successRate, 2),
                'avg_delivery_time_hours' => round($avgDeliveryTime, 2),
            ],
            'orders' => $orders->map(function ($order) {
                return [
                    'id' => $order->id,
                    'request_number' => $order->request_number,
                    'status' => $order->status,
                    'delivery_cost' => $order->delivery_cost,
                    'cod_amount' => $order->cod_amount,
                    'remittance_amount' => $order->remittance_amount,
                    'remittance_status' => $order->remittance_status ?: 'pending',
                    'payment_eligible' => $order->status === 'delivered',
                    'completed_at' => $order->completed_at?->toIso8601String(),
                ];
            }),
        ]);
    }

    public function settle(Request $request)
    {
        $user = auth()->user();
        if (!$user || !$user->hasAnyRole(['super_admin', 'operations_manager', 'accountant'])) {
            return $this->error('Access denied', 403);
        }

        $request->validate([
            'order_ids' => 'required|array',
            'order_ids.*' => 'exists:fulfillment_requests,id',
        ]);

        $orders = FulfillmentRequest::whereIn('id', $request->order_ids)
            ->where('status', 'delivered')
            ->get();

        if ($orders->isEmpty()) {
            return $this->error('No valid delivered orders found to settle', 400);
        }

        // Group by partner to create separate invoices if needed (though usually it's one partner at a time)
        $partnerId = $orders->first()->partnerCustomer?->partner_id;
        $totalCollected = $orders->sum('cod_amount');
        $totalFees = $orders->sum('delivery_cost');
        $totalRemittance = $totalCollected - $totalFees;

        $invoice = \App\Models\Invoice::create([
            'invoice_number' => 'SETTLE-MANUAL-' . date('YmdHis') . '-' . str_pad($partnerId, 4, '0', STR_PAD_LEFT),
            'customer_id' => $orders->first()->partnerCustomer?->customer_id ?? null,
            'subtotal' => $totalRemittance,
            'total_amount' => $totalRemittance,
            'status' => 'paid',
            'due_date' => now(),
            'notes' => "Manual settlement batch",
            'created_by' => $user->id,
        ]);

        \App\Models\InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => "Partner Settlement - {$orders->count()} orders",
            'quantity' => 1,
            'unit_price' => $totalRemittance,
            'amount' => $totalRemittance,
        ]);

        FulfillmentRequest::whereIn('id', $orders->pluck('id'))->update([
            'remittance_status' => 'settled',
            'remitted_at' => now(),
            'invoice_id' => $invoice->id,
        ]);

        return $this->success($invoice, 'Orders settled and invoice generated successfully');
    }

    public function dispute(Request $request)
    {
        $user = auth()->user();
        if (!$user || !$user->hasAnyRole(['super_admin', 'operations_manager', 'accountant'])) {
            return $this->error('Access denied', 403);
        }

        $request->validate([
            'order_id' => 'required|exists:fulfillment_requests,id',
            'note' => 'required|string',
        ]);

        $order = FulfillmentRequest::findOrFail($request->order_id);
        $order->update([
            'remittance_status' => 'disputed',
            'dispute_note' => $request->note,
        ]);

        return $this->success($order, 'Order marked as disputed');
    }

    public function generateInvoice(Request $request)
    {
        $user = auth()->user();
        if (!$user || !$user->hasAnyRole(['super_admin', 'operations_manager', 'accountant'])) {
            return $this->error('Access denied', 403);
        }

        $request->validate([
            'partner_id' => 'required',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'period' => 'required|in:daily,weekly,monthly',
        ]);

        $partnerId = $request->partner_id;
        $startDate = Carbon::parse($request->start_date)->startOfDay();
        $endDate = Carbon::parse($request->end_date)->endOfDay();
        $period = $request->period;

        $orders = FulfillmentRequest::whereHas('partnerCustomer', function ($q) use ($partnerId) {
            $q->where('partner_id', $partnerId);
        })
            ->where('status', 'delivered')
            ->whereBetween('completed_at', [$startDate, $endDate])
            ->get();

        if ($orders->isEmpty()) {
            return $this->error('No delivered orders found for this period', 404);
        }

        $partnerName = $orders->first()?->partnerCustomer?->partner?->company
            ?: $orders->first()?->partnerCustomer?->partner?->name
            ?: 'N/A';
        
        $totalCollected = $orders->sum('amount_collected');
        $totalFees = $orders->sum('delivery_cost');
        $totalRemittance = $totalCollected - $totalFees;

        $invoiceNumber = 'SETTLE-' . strtoupper($period) . '-' . date('Ymd') . '-' . str_pad($partnerId, 4, '0', STR_PAD_LEFT);

        // Create formal Invoice record
        $invoice = \App\Models\Invoice::create([
            'invoice_number' => $invoiceNumber,
            'customer_id' => $orders->first()->partnerCustomer?->customer_id ?? null,
            'subtotal' => $totalRemittance,
            'total_amount' => $totalRemittance,
            'status' => 'paid', // Mark as paid since it's a settlement record
            'due_date' => now(),
            'notes' => "Settlement for {$partnerName} - Period: {$period} ({$request->start_date} to {$request->end_date})",
            'created_by' => $user->id,
        ]);

        \App\Models\InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => "Partner Settlement ({$period}) - {$orders->count()} orders",
            'quantity' => 1,
            'unit_price' => $totalRemittance,
            'amount' => $totalRemittance,
        ]);

        // Link orders to this invoice
        FulfillmentRequest::whereIn('id', $orders->pluck('id'))->update([
            'invoice_id' => $invoice->id,
            'remittance_status' => 'settled',
            'remitted_at' => now(),
        ]);

        return $this->success([
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoiceNumber,
            'partner_id' => $partnerId,
            'partner_name' => $partnerName,
            'period' => $period,
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'total_deliveries' => $orders->count(),
            'total_collected' => $totalCollected,
            'total_fees' => $totalFees,
            'net_remittance' => $totalRemittance,
            'deliveries' => $orders->map(function ($order) {
                return [
                    'id' => $order->id,
                    'request_number' => $order->request_number,
                    'cod_amount' => $order->cod_amount,
                    'amount_collected' => $order->amount_collected,
                    'delivery_cost' => $order->delivery_cost,
                    'net_amount' => $order->amount_collected - $order->delivery_cost,
                    'completed_at' => $order->completed_at?->toIso8601String(),
                ];
            }),
        ]);
    }

    public function partnersList()
    {
        $user = auth()->user();
        if (!$user || !$user->hasAnyRole(['super_admin', 'operations_manager', 'accountant'])) {
            return $this->error('Access denied', 403);
        }

        $partners = User::whereHas('role', function ($q) {
            $q->where('name', 'partner')->orWhere('slug', 'partner');
        })->get()->map(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->company ?: $user->name ?: 'N/A',
            ];
        });

        return $this->success($partners);
    }

    public function notifyPartner(Request $request)
    {
        $user = auth()->user();
        if (!$user || !$user->hasAnyRole(['super_admin', 'operations_manager', 'accountant'])) {
            return $this->error('Access denied', 403);
        }

        $request->validate([
            'partner_id' => 'required',
            'order_ids' => 'required|array',
        ]);

        FulfillmentRequest::whereIn('id', $request->order_ids)
            ->update([
                'partner_notified_at' => now(),
            ]);

        return $this->success(null, 'Partner notified successfully');
    }
}