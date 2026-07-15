<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\StreamController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\OrderTrackingController;
use App\Http\Controllers\KitchenController;

Route::post('/auth/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
});

Route::get('/pedidos/seguimiento', [OrderTrackingController::class, 'track'])->middleware('throttle:30,1');

Route::get('/events', [StreamController::class, 'events']);

Route::get('/productos', [ProductController::class, 'index']);
Route::get('/custom/bases', [ProductController::class, 'customBases']);
Route::get('/custom/options', [ProductController::class, 'customOptions']);
Route::post('/registrar', [ProductController::class, 'store']);
Route::post('/reabastecer', [ProductController::class, 'reabastecer']);
Route::post('/precio', [ProductController::class, 'actualizarPrecio']);
Route::put('/productos/{codigo}', [ProductController::class, 'update']);

Route::get('/pedidos', [OrderController::class, 'index']);
Route::post('/pedidos/crear', [OrderController::class, 'store']);
Route::post('/pedidos/estado', [OrderController::class, 'updateStatus'])
    ->middleware(['auth:sanctum', 'kitchen.access:gerente,administrador']);
Route::get('/ventas', [OrderController::class, 'sales']);

Route::get('/mesas', [ReservationController::class, 'tables']);
Route::get('/reservaciones', [ReservationController::class, 'index']);
Route::post('/reservaciones/crear', [ReservationController::class, 'store']);

Route::middleware(['auth:sanctum', 'kitchen.access'])->group(function () {
    Route::get('/cocina/pedidos', [KitchenController::class, 'index']);
    Route::post('/cocina/estado', [KitchenController::class, 'updateStatus']);
    Route::post('/cocina/items/estado', [KitchenController::class, 'updateItemStatus']);
});

Route::get('/analytics/stats', [AnalyticsController::class, 'stats']);
