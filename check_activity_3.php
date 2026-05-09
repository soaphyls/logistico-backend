<?php
include 'vendor/autoload.php';
$app = include_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\FulfillmentRequest;
use Carbon\Carbon;

$date = Carbon::today();
echo "Today: " . $date->toDateString() . "\n";

$orders = FulfillmentRequest::whereDate('created_at', $date)
    ->orWhereDate('updated_at', $date)
    ->get();

echo "Orders count for today (created or updated): " . $orders->count() . "\n";

foreach ($orders as $order) {
    echo "ID: {$order->id}, Status: {$order->status}, Created: {$order->created_at}, Updated: {$order->updated_at}\n";
}

$all = FulfillmentRequest::limit(5)->orderBy('updated_at', 'desc')->get();
echo "\nLast 5 updated orders:\n";
foreach ($all as $order) {
    echo "ID: {$order->id}, Status: {$order->status}, Created: {$order->created_at}, Updated: {$order->updated_at}\n";
}
