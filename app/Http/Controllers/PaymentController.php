<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\CompanySetting;
use App\Mail\ReceiptMail;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PaymentController extends Controller
{
    protected $paymentService;

    public function __construct()
    {
        $this->paymentService = new PaymentService();
    }

    public function showPaymentPage($invoiceId, $token)
    {
        $invoice = Invoice::with(['customer', 'items', 'payments'])->findOrFail($invoiceId);
        
        if ($invoice->payment_link !== $token) {
            abort(404, 'Invalid payment link');
        }

        $companySettings = CompanySetting::first() ?? (object)[
            'company_name' => config('app.name', 'Logistico'),
            'phone' => '',
            'email' => '',
            'address' => '',
            'website' => '',
        ];

        $gatewayPublicKey = $this->paymentService->getGatewayPublicKey();
        $gatewayName = $this->paymentService->getGatewayName();

        return view('payment.index', compact('invoice', 'companySettings', 'gatewayPublicKey', 'gatewayName'));
    }

    public function initializePayment(Request $request, $invoiceId, $token)
    {
        $invoice = Invoice::with('customer')->findOrFail($invoiceId);
        
        if ($invoice->payment_link !== $token) {
            return response()->json(['error' => 'Invalid payment link'], 404);
        }

        $customer = [
            'name' => $invoice->customer->name,
            'email' => $request->input('payer_email', $invoice->customer->email),
        ];

        $result = $this->paymentService->initializePayment($invoice, $customer);

        if ($result['success']) {
            return response()->json($result);
        }

        return response()->json($result, 400);
    }

    public function handleCallback(Request $request, $invoiceId, $token)
    {
        $reference = $request->query('reference');
        
        if (!$reference) {
            return redirect('/pay/' . $invoiceId . '/' . $token . '?status=error&message=No reference provided');
        }

        $invoice = Invoice::with('customer')->findOrFail($invoiceId);
        
        if ($invoice->payment_link !== $token) {
            abort(404, 'Invalid payment link');
        }

        $result = $this->paymentService->verifyPayment($reference);

        if ($result['success']) {
            $payment = Payment::create([
                'invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'amount' => $result['amount'],
                'payment_method' => 'card',
                'payment_date' => now(),
                'reference_number' => $reference,
                'notes' => 'Online payment via ' . ucfirst($result['gateway']),
            ]);

            $invoice->amount_paid = $invoice->payments()->sum('amount');
            $invoice->balance_due = $invoice->total_amount - $invoice->amount_paid;
            
            if ($invoice->balance_due <= 0) {
                $invoice->status = 'paid';
                $invoice->paid_at = now();
            } elseif ($invoice->amount_paid > 0) {
                $invoice->status = 'partial';
            }
            
            $invoice->save();

            if ($invoice->customer->email) {
                try {
                    \Mail::to($invoice->customer->email)->send(new ReceiptMail($invoice, $payment));
                } catch (\Exception $e) {
                    Log::error('Failed to send receipt: ' . $e->getMessage());
                }
            }

            return redirect('/pay/' . $invoiceId . '/' . $token . '?status=success');
        }

        return redirect('/pay/' . $invoiceId . '/' . $token . '?status=error&message=Payment verification failed');
    }

    public function processPayment(Request $request, $invoiceId, $token)
    {
        $invoice = Invoice::with('customer')->findOrFail($invoiceId);
        
        if ($invoice->payment_link !== $token) {
            return response()->json(['error' => 'Invalid payment link'], 404);
        }

        $validated = $request->validate([
            'payment_method' => 'required|in:card,bank_transfer,cash,pos,cheque',
            'amount' => 'required|numeric|min:1',
            'reference_number' => 'nullable|string',
            'payer_name' => 'required|string',
            'payer_email' => 'required|email',
        ]);

        // For card payments, initialize payment gateway
        if ($validated['payment_method'] === 'card') {
            $customer = [
                'name' => $validated['payer_name'],
                'email' => $validated['payer_email'],
            ];

            $result = $this->paymentService->initializePayment($invoice, $customer);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'checkout_url' => $result['checkout_url'],
                    'reference' => $result['reference'],
                ]);
            }

            return response()->json(['success' => false, 'message' => $result['message']], 400);
        }

        // For non-card payments, process directly
        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'amount' => $validated['amount'],
            'payment_method' => $validated['payment_method'],
            'payment_date' => now(),
            'reference_number' => $validated['reference_number'] ?? null,
            'notes' => "Payment by {$validated['payer_name']}",
        ]);

        $invoice->amount_paid = $invoice->payments()->sum('amount');
        $invoice->balance_due = $invoice->total_amount - $invoice->amount_paid;
        
        if ($invoice->balance_due <= 0) {
            $invoice->status = 'paid';
            $invoice->paid_at = now();
        } elseif ($invoice->amount_paid > 0) {
            $invoice->status = 'partial';
        }
        
        $invoice->save();

        if ($invoice->customer->email) {
            try {
                \Mail::to($invoice->customer->email)->send(new ReceiptMail($invoice, $payment));
            } catch (\Exception $e) {
                Log::error('Failed to send receipt: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment processed successfully',
            'payment' => $payment,
            'invoice' => $invoice->fresh(),
        ]);
    }
}
