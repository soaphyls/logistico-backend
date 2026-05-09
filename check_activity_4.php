<?php
include 'vendor/autoload.php';
$app = include_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\FulfillmentRequest;

$orders = FulfillmentRequest::with(['partnerCustomer.partner', 'dispatcher.user'])->get();

foreach ($orders as $order) {
    $partnerId = $order->partnerCustomer?->partner_id;
    $partnerName = $order->partnerCustomer?->partner?->name;
    $dispatcherId = $order->dispatcher_id;
    $dispatcherName = $order->dispatcher?->user?->name;
    
    echo "ID: {$order->id}, PartnerID: {$partnerId} ({$partnerName}), DispatcherID: {$dispatcherId} ({$dispatcherName}), Status: {$order->status}, Updated: {$order->updated_at}\n";
}
