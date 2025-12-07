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

// Example: API routes for React frontend communication
Route::middleware(['verify.shopify'])->group(function () {
    // Add your custom API routes here for authenticated shops
    // Example:
    // Route::get('/api/sync-status', [SyncController::class, 'status']);
});
