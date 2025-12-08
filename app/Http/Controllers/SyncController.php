<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\SyncLog;
use App\Services\SyncService;
use App\Services\SyncManager;
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

    /**
     * Start sync with strategy (using SyncManager)
     */
    public function startSync(Request $request): JsonResponse
    {
        $shop = $request->user();

        if (!$shop instanceof User) {
            return response()->json(['error' => 'Shop not authenticated'], 401);
        }

        $validator = Validator::make($request->all(), [
            'strategy' => 'required|string|in:full,incremental,selective',
            'async' => 'sometimes|boolean',
            'conflict_resolution' => 'sometimes|string|in:shopify_wins,sheets_wins,manual,merge',
            'options' => 'sometimes|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $syncManager = new SyncManager($shop);
            $result = $syncManager->startSync(
                $request->get('strategy', SyncManager::STRATEGY_FULL),
                $request->get('async', true),
                $request->get('conflict_resolution'),
                $request->get('options', [])
            );

            return response()->json([
                'success' => true,
                'message' => 'Sync started successfully',
                'data' => $result,
            ]);
        } catch (Exception $e) {
            Log::error('Sync start error', [
                'shop_id' => $shop->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Sync start failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get sync progress
     */
    public function getProgress(Request $request, int $id): JsonResponse
    {
        $shop = $request->user();

        if (!$shop instanceof User) {
            return response()->json(['error' => 'Shop not authenticated'], 401);
        }

        try {
            $syncManager = new SyncManager($shop);
            $progress = $syncManager->getProgress($id);

            return response()->json([
                'success' => true,
                'data' => $progress,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Get import preview
     */
    public function getImportPreview(Request $request, int $id): JsonResponse
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

        $cacheKey = "import_preview:{$id}";
        $preview = \Illuminate\Support\Facades\Cache::get($cacheKey);

        if (!$preview) {
            return response()->json([
                'error' => 'Preview not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $preview,
        ]);
    }

    /**
     * Get field mappings
     */
    public function getFieldMappings(Request $request): JsonResponse
    {
        $shop = $request->user();

        if (!$shop instanceof User) {
            return response()->json(['error' => 'Shop not authenticated'], 401);
        }

        $mappings = \App\Models\SyncFieldMapping::where('shop_id', $shop->id)
            ->orderBy('display_order')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $mappings,
        ]);
    }

    /**
     * Save field mappings
     */
    public function saveFieldMappings(Request $request): JsonResponse
    {
        $shop = $request->user();

        if (!$shop instanceof User) {
            return response()->json(['error' => 'Shop not authenticated'], 401);
        }

        $validator = Validator::make($request->all(), [
            'mappings' => 'required|array',
            'mappings.*.shopify_field' => 'required|string',
            'mappings.*.sheet_column' => 'required|string',
            'mappings.*.is_active' => 'boolean',
            'mappings.*.display_order' => 'integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Delete existing mappings
            \App\Models\SyncFieldMapping::where('shop_id', $shop->id)->delete();

            // Create new mappings
            foreach ($request->get('mappings', []) as $mapping) {
                \App\Models\SyncFieldMapping::create([
                    'shop_id' => $shop->id,
                    'shopify_field' => $mapping['shopify_field'],
                    'sheet_column' => $mapping['sheet_column'],
                    'is_active' => $mapping['is_active'] ?? true,
                    'display_order' => $mapping['display_order'] ?? 0,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Field mappings saved successfully',
            ]);
        } catch (Exception $e) {
            Log::error('Failed to save field mappings', [
                'shop_id' => $shop->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save mappings: ' . $e->getMessage(),
            ], 500);
        }
    }
}
