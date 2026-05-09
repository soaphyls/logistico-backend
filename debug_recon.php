<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\PartnerCustomer;
use App\Models\FulfillmentRequest;

$users = User::with('role')->get();
echo "USERS:\n";
foreach($users as $u) {
    echo "ID: {$u->id}, Name: {$u->name}, Role: " . ($u->role->name ?? 'N/A') . "\n";
}

echo "\nPARTNER CUSTOMERS:\n";
$pcs = PartnerCustomer::all();
foreach($pcs as $pc) {
    echo "ID: {$pc->id}, PartnerID: {$pc->partner_id}, CreatedBy: {$pc->created_by}\n";
}

echo "\nRECENT DELIVERED ORDERS:\n";
$orders = FulfillmentRequest::where('status', 'delivered')->limit(5)->get();
foreach($orders as $o) {
    echo "ID: {$o->id}, PartnerCustomerID: {$o->partner_customer_id}, Status: {$o->status}, Remittance: {$o->remittance_status}\n";
}
