<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Dispatcher;

echo "Partners:" . PHP_EOL;
foreach(User::whereHas('role', function($q) { $q->where('name', 'partner'); })->get() as $u) {
    echo "ID: {$u->id}, Name: {$u->name}" . PHP_EOL;
}

echo PHP_EOL . "Dispatchers:" . PHP_EOL;
foreach(Dispatcher::with('user')->get() as $d) {
    echo "ID: {$d->id}, Name: " . ($d->user->name ?? 'N/A') . PHP_EOL;
}
