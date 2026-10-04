<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Documents légaux publics (URL à renseigner dans les stores)
Route::get('/privacy', [\App\Http\Controllers\LegalPageController::class, 'privacy']);
Route::get('/terms', [\App\Http\Controllers\LegalPageController::class, 'terms']);
