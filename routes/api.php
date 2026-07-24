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
use App\Http\Controllers\Admin\AdminMenuController;
use App\Http\Controllers\Admin\AdminProductController;
use App\Http\Controllers\Admin\AdminReservationController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminReportController;

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

    Route::apiResource('categories', AdminCategoryController::class)->except('destroy');
    Route::patch('/categories/{category}/status', [AdminCategoryController::class, 'updateStatus']);
    Route::apiResource('products', AdminProductController::class)
        ->except('destroy')
        ->parameters(['products' => 'product:codigo']);
    Route::patch('/products/{product:codigo}/status', [AdminProductController::class, 'updateStatus']);
    Route::patch('/products/{product:codigo}/availability', [AdminProductController::class, 'updateAvailability']);
    Route::apiResource('menus', AdminMenuController::class)->only(['index', 'store', 'show', 'update']);
    Route::patch('/menus/{menu}/status', [AdminMenuController::class, 'updateStatus']);
    Route::put('/menus/{menu}/products/{product:codigo}', [AdminMenuController::class, 'addProduct'])
        ->withoutScopedBindings();
    Route::delete('/menus/{menu}/products/{product:codigo}', [AdminMenuController::class, 'removeProduct'])
        ->withoutScopedBindings();
    Route::get('/inventory', [AdminInventoryController::class, 'index']);
    Route::post('/inventory/adjustments', [AdminInventoryController::class, 'adjust']);
    Route::get('/reports', [AdminReportController::class, 'index']);
    Route::get('/reports/exports/sales', [AdminReportController::class, 'exportSales']);
    Route::get('/reports/exports/orders', [AdminReportController::class, 'exportOrders']);

    Route::prefix('users')->middleware('administrator.access')->group(function () {
        Route::get('/', [AdminUserController::class, 'index']);
        Route::post('/', [AdminUserController::class, 'store']);
        Route::match(['put', 'patch'], '/{user}', [AdminUserController::class, 'update']);
        Route::patch('/{user}/role', [AdminUserController::class, 'updateRole']);
        Route::patch('/{user}/password', [AdminUserController::class, 'updatePassword']);
        Route::patch('/{user}/status', [AdminUserController::class, 'updateStatus']);
    });
});
