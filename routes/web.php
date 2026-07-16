<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminAuthController;

Route::get('/', function () {
    return redirect('/cliente');
});

Route::get('/cliente', function () {
    return view('cliente');
});

Route::get('/empleado/login', [AdminAuthController::class, 'showLogin'])->name('employee.login');
Route::post('/empleado/login', [AdminAuthController::class, 'login'])->name('employee.login.submit');

Route::middleware(['auth', 'admin.access'])->group(function () {
    Route::get('/empleado', function () {
        return view('empleado', [
            'adminApiToken' => session('admin_api_token'),
        ]);
    })->name('employee.dashboard');
    Route::post('/empleado/logout', [AdminAuthController::class, 'logout'])->name('employee.logout');
});

Route::get('/cocina', function () {
    return view('cocina');
});
