<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$admins = \App\Models\User::whereHas('role', function($q) {
    $q->whereIn('slug', ['super_admin', 'operations_manager', 'operations']);
})->pluck('id')->toArray();

dump($admins);
