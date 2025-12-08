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

    // Sync routes
    Route::prefix('sync')->name('sync.')->group(function () {
        Route::post('/products', [\App\Http\Controllers\SyncController::class, 'syncProducts'])->name('products');
        Route::post('/inventory', [\App\Http\Controllers\SyncController::class, 'syncInventory'])->name('inventory');
        Route::post('/from-sheets', [\App\Http\Controllers\SyncController::class, 'syncFromSheets'])->name('from-sheets');
        Route::post('/full', [\App\Http\Controllers\SyncController::class, 'fullSync'])->name('full');
        Route::post('/start', [\App\Http\Controllers\SyncController::class, 'startSync'])->name('start');
        Route::get('/status', [\App\Http\Controllers\SyncController::class, 'status'])->name('status');
        Route::get('/progress/{id}', [\App\Http\Controllers\SyncController::class, 'getProgress'])->name('progress');
        Route::get('/logs/{id}', [\App\Http\Controllers\SyncController::class, 'getSyncLog'])->name('logs.show');
        Route::get('/preview/{id}', [\App\Http\Controllers\SyncController::class, 'getImportPreview'])->name('preview');
    });
});
