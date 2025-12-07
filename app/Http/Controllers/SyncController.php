<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\SyncLog;
use App\Services\SyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;

class SyncController extends Controller
{
    /**
     * Sync products from Shopify to Google Sheets
     */
    public function syncProducts(Request $request): JsonResponse
    {
        $shop = $request->user();

        if (!$shop instanceof User) {
            return response()->json(['error' => 'Shop not authenticated'], 401);
        }

        try {
            $syncService = new SyncService($shop);
            $result = $syncService->syncProductsToSheets();

            return response()->json([
                'success' => true,
                'message' => 'Products synced successfully',
                'data' => $result,
            ]);
        } catch (Exception $e) {
            Log::error('Product sync error', [
                'shop_id' => $shop->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Sync failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sync inventory from Shopify to Google Sheets
     */
    public function syncInventory(Request $request): JsonResponse
    {
        $shop = $request->user();

        if (!$shop instanceof User) {
            return response()->json(['error' => 'Shop not authenticated'], 401);
        }

        try {
            $syncService = new SyncService($shop);
            $result = $syncService->syncInventoryToSheets();

            return response()->json([
                'success' => true,
                'message' => 'Inventory synced successfully',
                'data' => $result,
            ]);
        } catch (Exception $e) {
            Log::error('Inventory sync error', [
                'shop_id' => $shop->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Sync failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sync data from Google Sheets to Shopify
     */
    public function syncFromSheets(Request $request): JsonResponse
    {
        $shop = $request->user();

        if (!$shop instanceof User) {
            return response()->json(['error' => 'Shop not authenticated'], 401);
        }

        try {
            $syncService = new SyncService($shop);
            $result = $syncService->syncSheetsToShopify();

            return response()->json([
                'success' => true,
                'message' => 'Data synced from Google Sheets successfully',
                'data' => $result,
            ]);
        } catch (Exception $e) {
            Log::error('Sheets to Shopify sync error', [
                'shop_id' => $shop->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Sync failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Full bidirectional sync
     */
    public function fullSync(Request $request): JsonResponse
    {
        $shop = $request->user();

        if (!$shop instanceof User) {
            return response()->json(['error' => 'Shop not authenticated'], 401);
        }

        try {
            $syncService = new SyncService($shop);
            $result = $syncService->fullSync();

            return response()->json([
                'success' => true,
                'message' => 'Full sync completed successfully',
                'data' => $result,
            ]);
        } catch (Exception $e) {
            Log::error('Full sync error', [
                'shop_id' => $shop->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Full sync failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get sync status and history
     */
    public function status(Request $request): JsonResponse
    {
        $shop = $request->user();

        if (!$shop instanceof User) {
            return response()->json(['error' => 'Shop not authenticated'], 401);
        }

        $validator = Validator::make($request->all(), [
            'limit' => 'sometimes|integer|min:1|max:100',
            'status' => 'sometimes|string|in:pending,processing,completed,failed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $limit = $request->get('limit', 10);
        $status = $request->get('status');

        $query = SyncLog::where('shop_id', $shop->id)
            ->orderBy('created_at', 'desc');

        if ($status) {
            $query->where('status', $status);
        }

        $logs = $query->limit($limit)->get();

        return response()->json([
            'success' => true,
            'data' => $logs,
        ]);
    }

    /**
     * Get specific sync log details
     */
    public function getSyncLog(Request $request, int $id): JsonResponse
    {
        $shop = $request->user();

        if (!$shop instanceof User) {
            return response()->json(['error' => 'Shop not authenticated'], 401);
        }

        $syncLog = SyncLog::where('shop_id', $shop->id)
            ->where('id', $id)
            ->first();

        if (!$syncLog) {
            return response()->json([
                'error' => 'Sync log not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $syncLog,
        ]);
    }
}
