<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\FulfillmentRequest;

$req = FulfillmentRequest::find(13);
if ($req) {
    echo "Fulfillment Request 13:" . PHP_EOL;
    echo "  Partner Customer ID: " . $req->partner_customer_id . PHP_EOL;
    $pc = $req->partnerCustomer;
    if ($pc) {
        echo "  Partner Customer Table Fields:" . PHP_EOL;
        echo "    customer_id: " . ($pc->customer_id ?? 'NULL') . PHP_EOL;
        echo "    customer_name: " . ($pc->customer_name ?? 'NULL') . PHP_EOL;
        echo "    Customer relation: " . ($pc->customer ? 'EXISTS' : 'NULL') . PHP_EOL;
        if ($pc->customer) echo "    Customer Name: " . $pc->customer->name . PHP_EOL;
    }
}
