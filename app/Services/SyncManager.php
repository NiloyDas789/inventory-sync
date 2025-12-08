<?php

namespace App\Services;

use App\Models\User;
use App\Models\SyncLog;
use App\Jobs\ExportJob;
use App\Jobs\ImportJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

class SyncManager
{
    public const STRATEGY_FULL = 'full';
    public const STRATEGY_INCREMENTAL = 'incremental';
    public const STRATEGY_SELECTIVE = 'selective';

    public const CONFLICT_RESOLUTION_SHOPIFY_WINS = 'shopify_wins';
    public const CONFLICT_RESOLUTION_SHEETS_WINS = 'sheets_wins';
    public const CONFLICT_RESOLUTION_MANUAL = 'manual';
    public const CONFLICT_RESOLUTION_MERGE = 'merge';

    protected User $shop;
    protected SyncService $syncService;
    protected string $defaultStrategy = self::STRATEGY_FULL;
    protected string $defaultConflictResolution = self::CONFLICT_RESOLUTION_SHOPIFY_WINS;

    /**
     * Initialize sync manager
     */
    public function __construct(User $shop)
    {
        $this->shop = $shop;
        $this->syncService = new SyncService($shop);
    }

    /**
     * Start a sync operation with specified strategy
     */
    public function startSync(
        string $strategy = self::STRATEGY_FULL,
        bool $async = true,
        ?string $conflictResolution = null,
        ?array $options = []
    ): array {
        $conflictResolution = $conflictResolution ?? $this->defaultConflictResolution;

        // Validate strategy
        if (!in_array($strategy, [self::STRATEGY_FULL, self::STRATEGY_INCREMENTAL, self::STRATEGY_SELECTIVE])) {
            throw new Exception("Invalid sync strategy: {$strategy}");
        }

        // Validate conflict resolution
        $validResolutions = [
            self::CONFLICT_RESOLUTION_SHOPIFY_WINS,
            self::CONFLICT_RESOLUTION_SHEETS_WINS,
            self::CONFLICT_RESOLUTION_MANUAL,
            self::CONFLICT_RESOLUTION_MERGE,
        ];
        if (!in_array($conflictResolution, $validResolutions)) {
            throw new Exception("Invalid conflict resolution: {$conflictResolution}");
        }

        // Create sync log
        $syncLog = SyncLog::create([
            'shop_id' => $this->shop->id,
            'sync_type' => $strategy,
            'status' => 'pending',
        ]);

        if ($async) {
            // Dispatch async job based on strategy
            return $this->dispatchAsyncSync($strategy, $syncLog, $conflictResolution, $options);
        } else {
            // Execute sync synchronously
            return $this->executeSync($strategy, $syncLog, $conflictResolution, $options);
        }
    }

    /**
     * Execute full sync
     */
    public function executeFullSync(SyncLog $syncLog, string $conflictResolution, array $options = []): array
    {
        try {
            $syncLog->markAsStarted();

            // Export: Shopify to Sheets
            $exportResult = $this->syncService->syncProductsToSheets('full_export');
            
            // Import: Sheets to Shopify (with conflict resolution)
            $importResult = $this->syncService->syncSheetsToShopify('full_import');

            // Handle conflicts if any
            if (!empty($importResult['errors'])) {
                $this->handleConflicts($importResult['errors'], $conflictResolution, $syncLog);
            }

            $totalProcessed = ($exportResult['products_synced'] ?? 0) + ($importResult['records_updated'] ?? 0);
            $syncLog->markAsCompleted($totalProcessed);

            return [
                'success' => true,
                'sync_log_id' => $syncLog->id,
                'export' => $exportResult,
                'import' => $importResult,
            ];
        } catch (Exception $e) {
            $syncLog->markAsFailed($e->getMessage());
            throw $e;
        }
    }

    /**
     * Execute incremental sync (only changed items since last sync)
     */
    public function executeIncrementalSync(SyncLog $syncLog, string $conflictResolution, array $options = []): array
    {
        try {
            $syncLog->markAsStarted();

            // Get last sync timestamp
            $lastSync = SyncLog::where('shop_id', $this->shop->id)
                ->where('status', 'completed')
                ->where('sync_type', '!=', 'webhook')
                ->orderBy('completed_at', 'desc')
                ->first();

            $since = $lastSync?->completed_at ?? now()->subDays(30);

            // Fetch only products updated since last sync
            $inventoryService = new InventoryService($this->shop);
            $products = $inventoryService->fetchProductsSince($since);

            if (empty($products)) {
                $syncLog->markAsCompleted(0);
                return [
                    'success' => true,
                    'sync_log_id' => $syncLog->id,
                    'message' => 'No changes detected since last sync',
                    'products_synced' => 0,
                ];
            }

            // Transform and sync
            $transformer = new DataTransformer($this->shop);
            $sheetData = $transformer->transformShopifyToSheet($products);

            $connection = $this->shop->googleSheetsConnection;
            if (!$connection || !$connection->hasValidTokens()) {
                throw new Exception('Google Sheets not connected');
            }

            $sheetsService = new GoogleSheetsService();
            $sheetsService->setConnection($this->shop);
            
            // Update only changed rows in sheet
            $this->updateSheetIncremental($sheetData, $connection->sheet_id, $products);

            $syncLog->markAsCompleted(count($products));

            return [
                'success' => true,
                'sync_log_id' => $syncLog->id,
                'products_synced' => count($products),
            ];
        } catch (Exception $e) {
            $syncLog->markAsFailed($e->getMessage());
            throw $e;
        }
    }

    /**
     * Execute selective sync (specific products/variants)
     */
    public function executeSelectiveSync(SyncLog $syncLog, string $conflictResolution, array $options = []): array
    {
        try {
            $syncLog->markAsStarted();

            $productIds = $options['product_ids'] ?? [];
            $variantIds = $options['variant_ids'] ?? [];

            if (empty($productIds) && empty($variantIds)) {
                throw new Exception('No products or variants specified for selective sync');
            }

            $inventoryService = new InventoryService($this->shop);
            $products = [];

            // Fetch specific products
            if (!empty($productIds)) {
                foreach ($productIds as $productId) {
                    $product = $inventoryService->fetchProductDetails($productId);
                    if ($product) {
                        $products[] = $product;
                    }
                }
            }

            // Fetch specific variants
            if (!empty($variantIds)) {
                foreach ($variantIds as $variantId) {
                    $variant = $inventoryService->getVariantData($variantId);
                    if ($variant) {
                        // Convert variant to product-like structure
                        $products[] = $variant;
                    }
                }
            }

            if (empty($products)) {
                $syncLog->markAsCompleted(0);
                return [
                    'success' => true,
                    'sync_log_id' => $syncLog->id,
                    'message' => 'No products found',
                    'products_synced' => 0,
                ];
            }

            // Transform and sync
            $transformer = new DataTransformer($this->shop);
            $sheetData = $transformer->transformShopifyToSheet($products);

            $connection = $this->shop->googleSheetsConnection;
            if (!$connection || !$connection->hasValidTokens()) {
                throw new Exception('Google Sheets not connected');
            }

            $sheetsService = new GoogleSheetsService();
            $sheetsService->setConnection($this->shop);
            
            // Update specific rows in sheet
            $this->updateSheetSelective($sheetData, $connection->sheet_id, $products);

            $syncLog->markAsCompleted(count($products));

            return [
                'success' => true,
                'sync_log_id' => $syncLog->id,
                'products_synced' => count($products),
            ];
        } catch (Exception $e) {
            $syncLog->markAsFailed($e->getMessage());
            throw $e;
        }
    }

    /**
     * Dispatch async sync job
     */
    protected function dispatchAsyncSync(
        string $strategy,
        SyncLog $syncLog,
        string $conflictResolution,
        array $options
    ): array {
        if ($strategy === self::STRATEGY_FULL) {
            ExportJob::dispatch($this->shop->id, $syncLog->id, $conflictResolution, $options);
            ImportJob::dispatch($this->shop->id, $syncLog->id, $conflictResolution, $options);
        } else {
            // For incremental and selective, use a combined job
            ExportJob::dispatch($this->shop->id, $syncLog->id, $conflictResolution, array_merge($options, [
                'strategy' => $strategy,
            ]));
        }

        return [
            'success' => true,
            'sync_log_id' => $syncLog->id,
            'status' => 'queued',
            'strategy' => $strategy,
            'message' => 'Sync operation queued successfully',
        ];
    }

    /**
     * Execute sync synchronously
     */
    protected function executeSync(
        string $strategy,
        SyncLog $syncLog,
        string $conflictResolution,
        array $options
    ): array {
        return match ($strategy) {
            self::STRATEGY_FULL => $this->executeFullSync($syncLog, $conflictResolution, $options),
            self::STRATEGY_INCREMENTAL => $this->executeIncrementalSync($syncLog, $conflictResolution, $options),
            self::STRATEGY_SELECTIVE => $this->executeSelectiveSync($syncLog, $conflictResolution, $options),
            default => throw new Exception("Unknown strategy: {$strategy}"),
        };
    }

    /**
     * Handle conflicts between Shopify and Sheets data
     */
    protected function handleConflicts(array $errors, string $resolution, SyncLog $syncLog): void
    {
        Log::warning('Sync conflicts detected', [
            'shop_id' => $this->shop->id,
            'sync_log_id' => $syncLog->id,
            'conflicts_count' => count($errors),
            'resolution' => $resolution,
        ]);

        switch ($resolution) {
            case self::CONFLICT_RESOLUTION_SHOPIFY_WINS:
                // Shopify data takes precedence - already handled in sync
                break;

            case self::CONFLICT_RESOLUTION_SHEETS_WINS:
                // Sheets data takes precedence - re-import
                $this->syncService->syncSheetsToShopify('conflict_resolution');
                break;

            case self::CONFLICT_RESOLUTION_MANUAL:
                // Store conflicts for manual review
                $this->storeConflictsForReview($errors, $syncLog);
                break;

            case self::CONFLICT_RESOLUTION_MERGE:
                // Merge data intelligently
                $this->mergeConflicts($errors);
                break;
        }
    }

    /**
     * Store conflicts for manual review
     */
    protected function storeConflictsForReview(array $errors, SyncLog $syncLog): void
    {
        // Store in cache or database for review
        $cacheKey = "sync_conflicts:{$syncLog->id}";
        Cache::put($cacheKey, $errors, now()->addDays(7));
    }

    /**
     * Merge conflicts intelligently
     */
    protected function mergeConflicts(array $errors): void
    {
        // Implement intelligent merging logic
        // For now, log the conflicts
        Log::info('Merging conflicts', [
            'shop_id' => $this->shop->id,
            'conflicts' => $errors,
        ]);
    }

    /**
     * Update sheet incrementally
     */
    protected function updateSheetIncremental(array $sheetData, string $sheetId, array $products): void
    {
        $sheetsService = new GoogleSheetsService();
        $sheetsService->setConnection($this->shop);
        $transformer = new DataTransformer($this->shop);
        $columnMappings = $transformer->getFieldMappings();

        // Find and update existing rows based on product ID
        foreach ($products as $index => $product) {
            $productId = $product['id'] ?? null;
            if ($productId) {
                // Find row in sheet by product ID and update
                // This would require reading the sheet first to find the row
                $rowData = $sheetData[$index] ?? [];
                if (!empty($rowData)) {
                    // Update specific row
                    // Implementation depends on how product IDs are stored in sheet
                }
            }
        }
    }

    /**
     * Update sheet selectively
     */
    protected function updateSheetSelective(array $sheetData, string $sheetId, array $products): void
    {
        // Similar to incremental but for specific products
        $this->updateSheetIncremental($sheetData, $sheetId, $products);
    }

    /**
     * Get sync progress
     */
    public function getProgress(int $syncLogId): array
    {
        $syncLog = SyncLog::find($syncLogId);
        if (!$syncLog || $syncLog->shop_id !== $this->shop->id) {
            throw new Exception('Sync log not found');
        }

        $cacheKey = "sync_progress:{$syncLogId}";
        $progress = Cache::get($cacheKey, [
            'status' => $syncLog->status,
            'records_processed' => $syncLog->records_processed,
            'total_records' => null,
            'percentage' => 0,
        ]);

        return array_merge($progress, [
            'sync_log_id' => $syncLogId,
            'status' => $syncLog->status,
            'records_processed' => $syncLog->records_processed,
        ]);
    }

    /**
     * Update sync progress
     */
    public function updateProgress(int $syncLogId, int $processed, ?int $total = null): void
    {
        $percentage = $total ? (int) (($processed / $total) * 100) : 0;
        
        $cacheKey = "sync_progress:{$syncLogId}";
        Cache::put($cacheKey, [
            'records_processed' => $processed,
            'total_records' => $total,
            'percentage' => $percentage,
            'updated_at' => now()->toIso8601String(),
        ], now()->addHours(2));
    }
}


