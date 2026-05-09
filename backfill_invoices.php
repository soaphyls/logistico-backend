<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\FulfillmentRequest;
use App\Models\Invoice;

$order = FulfillmentRequest::find(9);
if($order && $order->remittance_status === 'settled' && !$order->invoice_id) {
    $invoice = Invoice::create([
        'invoice_number' => 'SETTLE-BACKFILL-001',
        'customer_id' => $order->partnerCustomer?->customer_id,
        'subtotal' => $order->cod_amount - $order->delivery_cost,
        'total_amount' => $order->cod_amount - $order->delivery_cost,
        'status' => 'paid',
        'due_date' => now(),
        'notes' => 'Backfilled settlement record',
        'created_by' => 1
    ]);
    $order->update(['invoice_id' => $invoice->id]);
    echo "Backfilled Order 9 with Invoice {$invoice->invoice_number}\n";
} else {
    echo "Order 9 already has invoice or not settled/found\n";
}
