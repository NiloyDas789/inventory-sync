<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| The Shopify package automatically registers routes for:
| - / (home) - protected by verify.shopify middleware
| - /authenticate - OAuth callback
| - /authenticate/token - Token exchange
| - /billing/* - Billing routes
| - /webhook/{type} - Webhook handler (protected by auth.webhook)
|
| Additional application routes can be added below.
| All routes that require shop authentication should use the verify.shopify middleware.
|
*/

// Google Sheets routes
Route::middleware(['verify.shopify'])->group(function () {
    Route::prefix('google-sheets')->name('google-sheets.')->group(function () {
        Route::get('/connect', [\App\Http\Controllers\GoogleSheetsController::class, 'connect'])->name('connect');
        Route::get('/callback', [\App\Http\Controllers\GoogleSheetsController::class, 'callback'])->name('callback');
        Route::post('/disconnect', [\App\Http\Controllers\GoogleSheetsController::class, 'disconnect'])->name('disconnect');
        Route::post('/test', [\App\Http\Controllers\GoogleSheetsController::class, 'test'])->name('test');
        Route::get('/status', [\App\Http\Controllers\GoogleSheetsController::class, 'status'])->name('status');
        Route::post('/validate-structure', [\App\Http\Controllers\GoogleSheetsController::class, 'validateStructure'])->name('validate-structure');
    });
});
