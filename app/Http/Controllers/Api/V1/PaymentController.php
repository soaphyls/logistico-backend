<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Notification;
use App\Mail\ReceiptMail;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $query = Payment::with(['invoice', 'customer']);

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->has('date_from')) {
            $query->whereDate('payment_date', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('payment_date', '<=', $request->date_to);
        }

        $payments = $query->orderBy('payment_date', 'desc')->paginate(20);

        return $this->success($payments);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
            'customer_id' => 'required|exists:customers,id',
            'amount' => 'required|numeric|min:0',
            'payment_method' => 'required|in:cash,bank_transfer,pos,cheque',
            'payment_date' => 'required|date',
            'reference_number' => 'nullable|string',
            'notes' => 'nullable|string',
            'send_receipt' => 'boolean',
        ]);

        $sendReceipt = $validated['send_receipt'] ?? true;
        unset($validated['send_receipt']);
        
        $validated['recorded_by'] = auth()->id();

        $payment = Payment::create($validated);
        $payment->load(['invoice', 'invoice.customer']);

        $invoice = $payment->invoice;
        if ($invoice) {
            $invoice->amount_paid = ($invoice->amount_paid ?? 0) + $payment->amount;
            $invoice->balance_due = $invoice->total_amount - $invoice->amount_paid;
            
            if ($invoice->balance_due <= 0) {
                $invoice->status = 'paid';
            } elseif ($invoice->status === 'paid') {
                $invoice->status = 'pending';
            }
            
            $invoice->save();
        }

        if ($sendReceipt && $invoice && $invoice->customer->email) {
            try {
                \Mail::to($invoice->customer->email)->send(new ReceiptMail($invoice, $payment));
                
                Notification::create([
                    'user_id' => $invoice->customer->user_id ?? $invoice->customer->created_by,
                    'title' => 'Payment Receipt Sent',
                    'message' => "Payment receipt for invoice {$invoice->invoice_number} has been sent",
                    'type' => 'payment',
                    'related_to_type' => Payment::class,
                    'related_to_id' => $payment->id,
                ]);
            } catch (\Exception $e) {
                \Log::error('Failed to send receipt email: ' . $e->getMessage());
            }
        }

        return $this->success($payment, 'Payment recorded successfully');
    }

    public function show(Payment $payment)
    {
        $payment->load(['invoice', 'customer', 'recordedBy']);
        return $this->success($payment);
    }

    public function update(Request $request, Payment $payment)
    {
        $validated = $request->validate([
            'amount' => 'sometimes|numeric|min:0',
            'payment_method' => 'sometimes|in:cash,bank_transfer,pos,cheque',
            'payment_date' => 'sometimes|date',
            'reference_number' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $oldAmount = $payment->amount;
        $payment->update($validated);

        if (isset($validated['amount']) && $validated['amount'] !== $oldAmount) {
            $invoice = $payment->invoice;
            if ($invoice) {
                $invoice->amount_paid = $invoice->payments()->sum('amount');
                $invoice->balance_due = $invoice->total_amount - $invoice->amount_paid;
                
                if ($invoice->balance_due <= 0) {
                    $invoice->status = 'paid';
                } elseif ($invoice->status === 'paid') {
                    $invoice->status = 'pending';
                }
                
                $invoice->save();
            }
        }

        return $this->success($payment, 'Payment updated successfully');
    }

    public function destroy(Payment $payment)
    {
        $invoice = $payment->invoice;
        $amount = $payment->amount;
        
        $payment->delete();

        if ($invoice) {
            $invoice->amount_paid = $invoice->payments()->sum('amount');
            $invoice->balance_due = $invoice->total_amount - $invoice->amount_paid;
            
            if ($invoice->balance_due > 0) {
                $invoice->status = 'pending';
            } else {
                $invoice->status = 'paid';
            }
            
            $invoice->save();
        }

        return $this->success(null, 'Payment deleted successfully');
    }
}
