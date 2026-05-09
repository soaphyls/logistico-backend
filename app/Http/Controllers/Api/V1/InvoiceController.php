<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Notification;
use App\Models\Payment;
use App\Mail\InvoiceMail;
use App\Mail\ReceiptMail;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $query = Invoice::with(['customer', 'shipment']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $invoices = $query->orderBy('created_at', 'desc')->paginate(20);

        return $this->success($invoices);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'shipment_id' => 'nullable|exists:shipments,id',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'tax_rate' => 'numeric|min:0|max:100',
            'discount' => 'numeric|min:0',
            'due_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $subtotal = 0;
        foreach ($validated['items'] as $item) {
            $item['amount'] = $item['quantity'] * $item['unit_price'];
            $subtotal += $item['amount'];
        }

        $taxAmount = $validated['tax_rate'] ?? 0;
        $taxAmount = $subtotal * ($taxAmount / 100);
        $discount = $validated['discount'] ?? 0;
        $totalAmount = $subtotal + $taxAmount - $discount;

        $invoiceData = [
            'customer_id' => $validated['customer_id'],
            'shipment_id' => $validated['shipment_id'] ?? null,
            'subtotal' => $subtotal,
            'tax_rate' => $validated['tax_rate'] ?? 0,
            'tax_amount' => $taxAmount,
            'discount' => $discount,
            'total_amount' => $totalAmount,
            'due_date' => $validated['due_date'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'created_by' => auth()->id(),
        ];

        $invoice = Invoice::create($invoiceData);

        foreach ($validated['items'] as $item) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'amount' => $item['quantity'] * $item['unit_price'],
            ]);
        }

        $invoice->load('items');

        return $this->success($invoice, 'Invoice created successfully', 201);
    }

    public function show(Invoice $invoice)
    {
        $invoice->load(['customer', 'shipment', 'items', 'payments', 'createdBy']);

        return $this->success($invoice);
    }

    public function update(Request $request, Invoice $invoice)
    {
        if ($invoice->status !== 'draft') {
            return $this->error('Only draft invoices can be edited', 400);
        }

        $validated = $request->validate([
            'tax_rate' => 'numeric|min:0|max:100',
            'discount' => 'numeric|min:0',
            'due_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $invoice->update($validated);

        return $this->success($invoice, 'Invoice updated successfully');
    }

    public function destroy(Invoice $invoice)
    {
        if ($invoice->status !== 'draft') {
            return $this->error('Only draft invoices can be deleted', 400);
        }

        $invoice->items()->delete();
        $invoice->delete();

        return $this->success(null, 'Invoice deleted successfully');
    }

    public function send(Request $request, Invoice $invoice)
    {
        $invoice->load(['customer', 'shipment', 'items']);
        
        $sendEmail = $request->boolean('send_email', true);
        
        if ($sendEmail && $invoice->customer->email) {
            try {
                \Mail::to($invoice->customer->email)->send(new InvoiceMail($invoice));
            } catch (\Exception $e) {
                \Log::error('Failed to send invoice email: ' . $e->getMessage());
            }
        }
        
        $invoice->update(['status' => 'sent']);

        Notification::create([
            'user_id' => $invoice->customer->user_id ?? $invoice->customer->created_by,
            'title' => 'Invoice Sent',
            'message' => "Invoice {$invoice->invoice_number} has been sent to {$invoice->customer->email}",
            'type' => 'invoice',
            'related_to_type' => Invoice::class,
            'related_to_id' => $invoice->id,
        ]);

        return $this->success($invoice, 'Invoice sent successfully');
    }

    public function payments(Request $request, Invoice $invoice)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,bank_transfer,pos,cheque',
            'payment_date' => 'required|date',
            'reference_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
        ]);

        $validated['invoice_id'] = $invoice->id;
        $validated['customer_id'] = $invoice->customer_id;
        $validated['recorded_by'] = auth()->id();

        Payment::create($validated);

        $totalPaid = $invoice->payments()->sum('amount');
        
        if ($totalPaid >= $invoice->total_amount) {
            $invoice->update(['status' => 'paid']);
        } elseif ($totalPaid > 0) {
            $invoice->update(['status' => 'partial']);
        }

        $invoice->load('payments');

        return $this->success($invoice, 'Payment recorded successfully');
    }

    public function overdue()
    {
        $invoices = Invoice::with('customer')
            ->where('status', 'sent')
            ->whereDate('due_date', '<', now())
            ->orderBy('due_date', 'asc')
            ->paginate(20);

        return $this->success($invoices);
    }

    public function generatePaymentLink(Invoice $invoice)
    {
        $paymentUrl = Invoice::generatePaymentLink($invoice);
        
        return $this->success([
            'payment_link' => $paymentUrl,
            'invoice' => $invoice->fresh(),
        ], 'Payment link generated successfully');
    }

    public function getPaymentLink(Invoice $invoice)
    {
        if (!$invoice->payment_link) {
            $paymentUrl = Invoice::generatePaymentLink($invoice);
        } else {
            $paymentUrl = $invoice->payment_url;
        }
        
        return $this->success([
            'payment_link' => $paymentUrl,
            'invoice' => $invoice,
        ]);
    }

    public function summary()
    {
        $overdueAmount = Invoice::where('status', 'overdue')->sum('total_amount');
        $draftedTotals = Invoice::where('status', 'draft')->sum('total_amount');
        $unpaidTotals = Invoice::whereIn('status', ['sent', 'partial'])->sum('total_amount');
        
        $paidInvoices = Invoice::where('status', 'paid')
            ->whereNotNull('paid_at')
            ->get();
            
        $avgPaidTime = 0;
        if ($paidInvoices->count() > 0) {
            $totalDays = 0;
            foreach ($paidInvoices as $invoice) {
                $totalDays += $invoice->created_at->diffInDays($invoice->paid_at);
            }
            $avgPaidTime = round($totalDays / $paidInvoices->count());
        }

        $counts = [
            'all' => Invoice::count(),
            'paid' => Invoice::where('status', 'paid')->count(),
            'overdue' => Invoice::where('status', 'overdue')->count(),
            'pending' => Invoice::whereIn('status', ['sent', 'partial'])->count(),
            'draft' => Invoice::where('status', 'draft')->count(),
        ];

        return $this->success([
            'overdue_amount' => $overdueAmount,
            'drafted_totals' => $draftedTotals,
            'unpaid_totals' => $unpaidTotals,
            'avg_paid_time' => $avgPaidTime,
            'counts' => $counts,
        ]);
    }
}
