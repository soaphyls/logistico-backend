<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\FulfillmentRequest;
use App\Models\PickupDelivery;
use Carbon\Carbon;

// Mock the request
$dispatcherId = 2; // Sunday Akpu
$dateStr = '2026-05-07';
$date = Carbon::parse($dateStr);

echo "Testing Dispatcher ID: {$dispatcherId} for Date: {$dateStr}" . PHP_EOL;

// Logic from ActivityController
$query = FulfillmentRequest::with([
    'partnerCustomer.partner',
    'partnerProduct',
    'dispatcher.user',
])->where('dispatcher_id', $dispatcherId);

$query->where(function($q) use ($date) {
    $q->whereDate('created_at', $date)
      ->orWhereDate('completed_at', $date)
      ->orWhereDate('failed_at', $date)
      ->orWhereDate('updated_at', $date)
      ->orWhereDate('requested_at', $date)
      ->orWhereDate('cancelled_at', $date);
});

$partnerOrders = $query->get();
echo "Fulfillment Requests Found: " . $partnerOrders->count() . PHP_EOL;

$pickupQuery = PickupDelivery::with(['shipment', 'dispatcher.user'])
    ->where('dispatcher_id', $dispatcherId);

$pickupQuery->where(function($q) use ($date) {
    $q->whereDate('created_at', $date)
      ->orWhereDate('scheduled_date', $date)
      ->orWhereDate('actual_date', $date)
      ->orWhereDate('updated_at', $date);
});

$standardTasks = $pickupQuery->get();
echo "Standard Tasks Found: " . $standardTasks->count() . PHP_EOL;

// Mock mapOrders
$all = $partnerOrders->concat($standardTasks->map(function($t){ return (object)['status'=>$t->status, 'is_standard'=>true]; }));

$successful = $all->whereIn('status', ['delivered', 'completed']);
echo "Successful Count: " . $successful->count() . PHP_EOL;
foreach($all as $o) {
    echo "  Order Status: " . $o->status . PHP_EOL;
}
