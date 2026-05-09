<?php
include 'vendor/autoload.php';
$app = include_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\Api\V1\ActivityController;
use Illuminate\Http\Request;

$controller = new ActivityController();
$request = new Request([
    'dispatcher_id' => 2,
    'date' => '2026-05-07'
]);

// Mock auth
$user = \App\Models\User::whereHas('role', function($q) { $q->where('name', 'super_admin'); })->first();
auth()->login($user);

$response = $controller->dispatcherDaily($request);
echo json_encode($response->getData(), JSON_PRETTY_PRINT);
