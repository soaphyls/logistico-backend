<?php

namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Seeder;

class InvoiceSeeder extends Seeder
{
    public function run(): void
    {
        $accountant = User::where('email', 'accountant@logistico.com')->first();
        
        $invoices = [
            ['shipment_id' => 1, 'customer_id' => 1, 'subtotal' => 15000, 'tax_rate' => 7.5, 'tax_amount' => 1125, 'discount' => 0, 'total_amount' => 16125, 'status' => 'paid', 'due_date' => now()->subDays(5), 'invoice_number' => 'INV-20260214-0001', 'created_at' => now()->subDays(10)],
            ['shipment_id' => 4, 'customer_id' => 4, 'subtotal' => 25000, 'tax_rate' => 7.5, 'tax_amount' => 1875, 'discount' => 500, 'total_amount' => 26375, 'status' => 'sent', 'due_date' => now()->addDays(10), 'invoice_number' => 'INV-20260221-0001', 'created_at' => now()->subDays(3)],
            ['shipment_id' => 5, 'customer_id' => 5, 'subtotal' => 8000, 'tax_rate' => 7.5, 'tax_amount' => 600, 'discount' => 0, 'total_amount' => 8600, 'status' => 'overdue', 'due_date' => now()->subDays(2), 'invoice_number' => 'INV-20260209-0001', 'created_at' => now()->subDays(15)],
            ['shipment_id' => 8, 'customer_id' => 7, 'subtotal' => 5000, 'tax_rate' => 7.5, 'tax_amount' => 375, 'discount' => 0, 'total_amount' => 5375, 'status' => 'paid', 'due_date' => now()->subDays(10), 'invoice_number' => 'INV-20260217-0001', 'created_at' => now()->subDays(7)],
        ];

        foreach ($invoices as $index => $invoiceData) {
            $createdAt = $invoiceData['created_at'] ?? now();
            unset($invoiceData['created_at']);
            
            $invoiceData['created_by'] = $accountant->id;
            $invoice = Invoice::create($invoiceData);
            $invoice->created_at = $createdAt;
            $invoice->save();

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => 'Shipping charges - Shipment #' . ($index + 1),
                'quantity' => 1,
                'unit_price' => $invoiceData['subtotal'],
                'amount' => $invoiceData['subtotal'],
            ]);

            if ($invoiceData['status'] === 'paid') {
                Payment::create([
                    'invoice_id' => $invoice->id,
                    'customer_id' => $invoiceData['customer_id'],
                    'amount' => $invoiceData['total_amount'],
                    'payment_method' => 'bank_transfer',
                    'payment_date' => now()->subDays(rand(1, 5)),
                    'recorded_by' => $accountant->id,
                ]);
            }
        }
    }
}
