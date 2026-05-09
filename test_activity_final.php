<?php
include 'vendor/autoload.php';
$app = include_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\Api\V1\ActivityController;
use Illuminate\Http\Request;

$controller = new ActivityController();

// Mock auth
$user = \App\Models\User::first();
auth()->login($user);

echo "--- Dispatcher 2 Activity ---\n";
$req1 = new Request(['dispatcher_id' => 2, 'date' => '2026-05-07']);
$res1 = $controller->dispatcherDaily($req1);
$data1 = $res1->getData();
echo "Summary: " . json_encode($data1->data->summary) . "\n";
foreach ($data1->data->details as $cat => $orders) {
    echo "Category $cat: " . count($orders) . " orders\n";
}

echo "\n--- Partner 18 Activity ---\n";
$req2 = new Request(['partner_id' => 18, 'date' => '2026-05-07']);
$res2 = $controller->partnerDaily($req2);
$data2 = $res2->getData();
echo "Summary: " . json_encode($data2->data->summary) . "\n";
foreach ($data2->data->details as $cat => $orders) {
    echo "Category $cat: " . count($orders) . " orders\n";
}
