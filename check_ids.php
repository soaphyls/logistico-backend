<?php
include 'vendor/autoload.php';
$app = include_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Dispatcher;

$partners = User::whereHas('role', function($q) { $q->where('name', 'partner'); })->get();
echo "Partners:\n";
foreach ($partners as $p) {
    echo "ID: {$p->id}, Name: {$p->name}\n";
}

$dispatchers = Dispatcher::with('user')->get();
echo "\nDispatchers:\n";
foreach ($dispatchers as $d) {
    echo "ID: {$d->id}, UserID: {$d->user_id}, Name: {$d->user?->name}\n";
}
