<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PaymentController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/pay/{invoice}/{token}', [PaymentController::class, 'showPaymentPage']);
Route::post('/pay/{invoice}/{token}/initialize', [PaymentController::class, 'initializePayment']);
Route::get('/pay/{invoice}/{token}/callback', [PaymentController::class, 'handleCallback']);
Route::post('/pay/{invoice}/{token}', [PaymentController::class, 'processPayment']);
