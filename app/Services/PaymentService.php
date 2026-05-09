<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    private $gateway;
    private $config;

    public function __construct()
    {
        $this->gateway = config('app.payment_gateway', 'squad');
        $this->config = [
            'squad' => [
                'public_key' => config('services.squad.public_key'),
                'secret_key' => config('services.squad.secret_key'),
                'base_url' => config('services.squad.base_url', 'https://api.squadco.com'),
            ],
            'paystack' => [
                'public_key' => config('services.paystack.public_key'),
                'secret_key' => config('services.paystack.secret_key'),
                'base_url' => config('services.paystack.base_url', 'https://api.paystack.co'),
            ],
        ];
    }

    public function initializePayment(Invoice $invoice, array $customer)
    {
        if ($this->gateway === 'paystack') {
            return $this->initializePaystack($invoice, $customer);
        }
        return $this->initializeSquad($invoice, $customer);
    }

    private function initializeSquad(Invoice $invoice, array $customer)
    {
        $config = $this->config['squad'];
        $amount = (int) ($invoice->balance_due * 100);

        $data = [
            'email' => $customer['email'],
            'amount' => $amount,
            'currency' => $invoice->currency ?? 'NGN',
            'customer_name' => $customer['name'],
            'transaction_ref' => 'INV-' . $invoice->invoice_number . '-' . time(),
            'callback_url' => url("/pay/{$invoice->id}/{$invoice->payment_link}/callback"),
            'metadata' => [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'customer_id' => $invoice->customer_id,
            ],
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $config['secret_key'],
                'Content-Type' => 'application/json',
            ])->post($config['base_url'] . '/transaction/initialize', $data);

            $result = $response->json();

            if ($result['status'] ?? false) {
                return [
                    'success' => true,
                    'gateway' => 'squad',
                    'checkout_url' => $result['data']['checkout_url'],
                    'reference' => $result['data']['transaction_ref'],
                ];
            }

            Log::error('Squad initialization failed', $result);
            return ['success' => false, 'message' => $result['message'] ?? 'Payment initialization failed'];
        } catch (\Exception $e) {
            Log::error('Squad payment error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function initializePaystack(Invoice $invoice, array $customer)
    {
        $config = $this->config['paystack'];
        $amount = (int) ($invoice->balance_due * 100);

        $data = [
            'email' => $customer['email'],
            'amount' => $amount,
            'currency' => $invoice->currency ?? 'NGN',
            'reference' => 'INV-' . $invoice->invoice_number . '-' . time(),
            'callback_url' => url("/pay/{$invoice->id}/{$invoice->payment_link}/callback"),
            'metadata' => [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'customer_id' => $invoice->customer_id,
            ],
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $config['secret_key'],
                'Content-Type' => 'application/json',
            ])->post($config['base_url'] . '/transaction/initialize', $data);

            $result = $response->json();

            if ($result['status'] ?? false) {
                return [
                    'success' => true,
                    'gateway' => 'paystack',
                    'checkout_url' => $result['data']['authorization_url'],
                    'reference' => $result['data']['reference'],
                ];
            }

            Log::error('Paystack initialization failed', $result);
            return ['success' => false, 'message' => $result['message'] ?? 'Payment initialization failed'];
        } catch (\Exception $e) {
            Log::error('Paystack payment error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function verifyPayment(string $reference, string $gateway = null)
    {
        $gateway = $gateway ?? $this->gateway;

        if ($gateway === 'paystack') {
            return $this->verifyPaystack($reference);
        }
        return $this->verifySquad($reference);
    }

    private function verifySquad(string $reference)
    {
        $config = $this->config['squad'];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $config['secret_key'],
            ])->get($config['base_url'] . '/transaction/verify/' . $reference);

            $result = $response->json();

            if (($result['status'] ?? false) && ($result['data']['status'] ?? '') === 'success') {
                return [
                    'success' => true,
                    'amount' => ($result['data']['amount'] ?? 0) / 100,
                    'reference' => $result['data']['transaction_ref'],
                    'gateway' => 'squad',
                ];
            }

            return ['success' => false, 'message' => 'Payment not verified'];
        } catch (\Exception $e) {
            Log::error('Squad verification error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function verifyPaystack(string $reference)
    {
        $config = $this->config['paystack'];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $config['secret_key'],
            ])->get($config['base_url'] . '/transaction/verify/' . $reference);

            $result = $response->json();

            if (($result['status'] ?? false) && ($result['data']['status'] ?? '') === 'success') {
                return [
                    'success' => true,
                    'amount' => ($result['data']['amount'] ?? 0) / 100,
                    'reference' => $result['data']['reference'],
                    'gateway' => 'paystack',
                ];
            }

            return ['success' => false, 'message' => 'Payment not verified'];
        } catch (\Exception $e) {
            Log::error('Paystack verification error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getGatewayPublicKey(): string
    {
        if ($this->gateway === 'paystack') {
            return $this->config['paystack']['public_key'] ?? '';
        }
        return $this->config['squad']['public_key'] ?? '';
    }

    public function getGatewayName(): string
    {
        return ucfirst($this->gateway);
    }
}
