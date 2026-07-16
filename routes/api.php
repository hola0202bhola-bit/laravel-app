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
use App\Http\Controllers\Admin\AdminCategoryController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminInventoryController;
use App\Http\Controllers\Admin\AdminProductController;
use App\Http\Controllers\Admin\AdminReservationController;

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

Route::post('/pedidos/crear', [OrderController::class, 'store']);
Route::post('/pedidos/estado', [OrderController::class, 'updateStatus'])
    ->middleware(['auth:sanctum', 'kitchen.access:gerente,administrador']);

Route::get('/mesas', [ReservationController::class, 'tables']);
Route::post('/reservaciones/crear', [ReservationController::class, 'store']);

Route::middleware(['auth:sanctum', 'kitchen.access'])->group(function () {
    Route::get('/cocina/pedidos', [KitchenController::class, 'index']);
    Route::post('/cocina/estado', [KitchenController::class, 'updateStatus']);
    Route::post('/cocina/items/estado', [KitchenController::class, 'updateItemStatus']);
});

Route::prefix('admin')->middleware(['auth:sanctum', 'admin.access'])->group(function () {
    Route::get('/dashboard', [AdminDashboardController::class, 'index']);
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/sales', [OrderController::class, 'sales']);
    Route::get('/analytics', [AnalyticsController::class, 'stats']);
    Route::patch('/reservations/{reservation}/status', [AdminReservationController::class, 'updateStatus']);
    Route::apiResource('reservations', AdminReservationController::class)
        ->only(['index', 'show', 'update', 'destroy']);

    Route::apiResource('categories', AdminCategoryController::class);
    Route::apiResource('products', AdminProductController::class)
        ->parameters(['products' => 'product:codigo']);
    Route::get('/inventory', [AdminInventoryController::class, 'index']);
    Route::post('/inventory/adjustments', [AdminInventoryController::class, 'adjust']);
});
