<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Swagger Documentation Route
Route::get('/docs', function () {
    return redirect('/api/documentation');
})->name('docs');

// Direct access to API documentation
Route::get('/api/docs', function () {
    return redirect('/api/documentation');
});
