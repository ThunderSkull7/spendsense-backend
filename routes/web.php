<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Return the welcome view for the application's home page.
|
*/

Route::get('/', function () {
    return view('welcome');
});

// authenticated user endpoint
Route::get('/user', function () {
    return auth()->user();
})->middleware('auth:sanctum');
