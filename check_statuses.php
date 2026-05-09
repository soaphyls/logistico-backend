<?php
include 'vendor/autoload.php';
$app = include_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$statuses = DB::table('fulfillment_requests')->select('status', DB::raw('count(*) as count'))->groupBy('status')->get();
print_r($statuses);

$dispatcherOrders = DB::table('fulfillment_requests')->where('dispatcher_id', 2)->get();
echo "\nDispatcher 2 orders:\n";
foreach ($dispatcherOrders as $order) {
    echo "ID: {$order->id}, Status: {$order->status}, Updated: {$order->updated_at}\n";
}
