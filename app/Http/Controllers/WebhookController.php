<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Jobs\InventoryLevelsUpdateJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Exception;

class WebhookController extends Controller
{
    /**
     * Handle inventory levels update webhook
     * This is automatically called by the Shopify package when webhook is received
     */
    public function handleInventoryLevelsUpdate(Request $request): JsonResponse
    {
        try {
            // Get shop from request (set by auth.webhook middleware)
            $shop = $request->user();
            
            if (!$shop instanceof User) {
                Log::error('Invalid shop in webhook', [
                    'request_data' => $request->all(),
                ]);
                return response()->json(['error' => 'Invalid shop'], 401);
            }

            $webhookData = $request->all();

            Log::info('Inventory levels update webhook received', [
                'shop_id' => $shop->id,
                'webhook_data' => $webhookData,
            ]);

            // Dispatch job to process webhook asynchronously
            InventoryLevelsUpdateJob::dispatch($shop->id, $webhookData);

            return response()->json(['success' => true], 200);
        } catch (Exception $e) {
            Log::error('Error handling inventory levels webhook', [
                'error' => $e->getMessage(),
                'request_data' => $request->all(),
            ]);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }
}
