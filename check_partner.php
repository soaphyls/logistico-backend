<?php
$user = App\Models\User::where('email', 'afromedi@gmail.com')->with('role')->first();
if (!$user) {
    echo "User NOT FOUND\n";
    exit;
}
echo "User: " . $user->name . "\n";
echo "Role: " . ($user->role?->slug ?? 'no role') . "\n";
echo "ID: " . $user->id . "\n";
echo "parent_id: " . ($user->parent_id ?? 'null') . "\n";

$ownerId = $user->parent_id ?? $user->id;
echo "ownerId used: " . $ownerId . "\n";

$pcs = App\Models\PartnerCustomer::where('partner_id', $ownerId)->orWhere('created_by', $ownerId)->pluck('id');
echo "PartnerCustomer IDs: " . $pcs->implode(',') . "\n";

$products = App\Models\PartnerProduct::whereIn('partner_customer_id', $pcs)->get(['id','name','is_approved','partner_customer_id']);
echo "Products count: " . $products->count() . "\n";
foreach ($products as $p) {
    echo "  - [" . $p->id . "] " . $p->name . " | approved=" . ($p->is_approved ? 'yes' : 'no') . " | pc_id=" . $p->partner_customer_id . "\n";
}

echo "\n--- ALL partner_products in DB ---\n";
$all = App\Models\PartnerProduct::latest()->take(10)->get(['id','name','is_approved','partner_customer_id','created_at']);
foreach ($all as $p) {
    echo "  - [" . $p->id . "] " . $p->name . " | approved=" . ($p->is_approved ? 'yes' : 'no') . " | pc_id=" . $p->partner_customer_id . " | created=" . $p->created_at . "\n";
}
