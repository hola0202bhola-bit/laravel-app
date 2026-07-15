<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\StreamController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\KitchenController;
use App\Http\Controllers\AnalyticsController;

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
Route::post('/pedidos/estado', [OrderController::class, 'updateStatus']);
Route::get('/ventas', [OrderController::class, 'sales']);

Route::get('/mesas', [ReservationController::class, 'tables']);
Route::get('/reservaciones', [ReservationController::class, 'index']);
Route::post('/reservaciones/crear', [ReservationController::class, 'store']);

Route::get('/cocina/pedidos', [KitchenController::class, 'index']);
Route::post('/cocina/estado', [KitchenController::class, 'updateStatus']);
Route::post('/cocina/items/estado', [KitchenController::class, 'updateItemStatus']);

Route::get('/analytics/stats', [AnalyticsController::class, 'stats']);
