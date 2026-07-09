<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/cliente');
});

Route::get('/cliente', function () {
    return view('cliente');
});

Route::get('/empleado', function () {
    return view('empleado');
});

Route::get('/cocina', function () {
    return view('cocina');
});
