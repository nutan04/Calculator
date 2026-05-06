<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AreaPriceController;
use App\Http\Controllers\LocationController;

// Route::get('/', function () {
//     return view('welcome');
// });
Route::get('/', function () {
    return view('home');
});
Route::get('/new', function () {
    return "hello";
});
Route::post('/price-estimate', [AreaPriceController::class, 'getPrice']);
Route::post('/price-estimate-client', [AreaPriceController::class, 'getPriceClient']);
Route::get('/countries', [LocationController::class, 'countries']);
Route::get('/states/india', [LocationController::class, 'getIndiaStates']);
Route::get('/cities', [LocationController::class, 'getCities']);
Route::post('/area-price/upload', [AreaPriceController::class, 'upload']);
Route::get('/area-price/download-format', [AreaPriceController::class, 'downloadFormat']);
