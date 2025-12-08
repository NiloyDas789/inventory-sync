<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\SyncLog;
use App\Services\InventoryService;
use App\Services\GoogleSheetsService;
use App\Services\DataTransformer;
use App\Services\SyncManager;
use App\Notifications\SyncProgressNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

class ImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600; // 1 hour
    public int $tries = 3;
    public int $backoff = 60; // 1 minute

    protected int $shopId;
    protected int $syncLogId;
    protected string $conflictResolution;
    protected array $options;
    protected bool $dryRun;
    protected bool $previewOnly;

    /**
     * Create a new job instance.
     */
    public function __construct(
        int $shopId,
        int $syncLogId,
        string $conflictResolution = SyncManager::CONFLICT_RESOLUTION_SHOPIFY_WINS,
        array $options = []
    ) {
        $this->shopId = $shopId;
        $this->syncLogId = $syncLogId;
        $this->conflictResolution = $conflictResolution;
        $this->options = $options;
        $this->dryRun = $options['dry_run'] ?? false;
        $this->previewOnly = $options['preview_only'] ?? false;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $shop = User::find($this->shopId);
        if (!$shop) {
            Log::error('Shop not found for import job', ['shop_id' => $this->shopId]);
            return;
        }

        $syncLog = SyncLog::find($this->syncLogId);
        if (!$syncLog || $syncLog->shop_id !== $this->shopId) {
            Log::error('Sync log not found for import job', [
                'shop_id' => $this->shopId,
                'sync_log_id' => $this->syncLogId,
            ]);
            return;
        }

        try {
            $syncLog->markAsStarted();
            $this->notifyProgress($shop, $syncLog, 0, $this->previewOnly ? 'Generating preview...' : 'Starting import...');

            $sheetsService = new GoogleSheetsService();
            $sheetsService->setConnection($shop);

            $transformer = new DataTransformer($shop);
            $inventoryService = new InventoryService($shop);
            $inventoryService->setSyncLog($syncLog);
            $syncManager = new SyncManager($shop);

            // Check connection
            $connection = $shop->googleSheetsConnection;
            if (!$connection || !$connection->hasValidTokens() || !$connection->sheet_id) {
                throw new Exception('Google Sheets not connected');
            }

            // Read data from sheet with pagination
            $sheetData = $this->readSheetData($sheetsService, $connection->sheet_id, $transformer);
            
            if (empty($sheetData)) {
                $syncLog->markAsCompleted(0);
                $this->notifyProgress($shop, $syncLog, 0, 'No data found in sheet');
                return;
            }

            // Validate data
            $validation = $transformer->validateImportData($sheetData);
            
            if (!empty($validation['errors'])) {
                $errorMessage = 'Data validation failed: ' . count($validation['errors']) . ' errors found';
                if ($this->previewOnly || $this->dryRun) {
                    // Store errors for preview
                    $this->storePreviewData($validation, $syncLog);
                    $syncLog->markAsCompleted(0);
                    $this->notifyProgress($shop, $syncLog, 0, $errorMessage);
                    return;
                }
                throw new Exception($errorMessage);
            }

            // Transform to Shopify format
            $shopifyData = $transformer->transformSheetToShopify($validation['valid']);

            // Generate preview if requested
            if ($this->previewOnly) {
                $preview = $this->generatePreview($shopifyData, $inventoryService);
                $this->storePreviewData($preview, $syncLog);
                $syncLog->markAsCompleted(count($shopifyData));
                $this->notifyProgress($shop, $syncLog, count($shopifyData), 'Preview generated');
                return;
            }

            // Dry run: validate without applying changes
            if ($this->dryRun) {
                $dryRunResults = $this->performDryRun($shopifyData, $inventoryService);
                $this->storePreviewData($dryRunResults, $syncLog);
                $syncLog->markAsCompleted(count($shopifyData));
                $this->notifyProgress($shop, $syncLog, count($shopifyData), 'Dry run completed');
                return;
            }

            // Actual import: Update Shopify
            $results = $this->updateShopify($shopifyData, $inventoryService, $syncManager);

            $syncLog->updateRecordsProcessed(count($results['success']));
            $syncLog->markAsCompleted(count($results['success']));

            // Store detailed error report
            if (!empty($results['errors'])) {
                $this->storeErrorReport($results['errors'], $syncLog);
            }

            $this->notifyProgress(
                $shop,
                $syncLog,
                count($results['success']),
                "Import completed: " . count($results['success']) . " updated, " . count($results['errors']) . " errors"
            );

            Log::info('Import job completed', [
                'shop_id' => $this->shopId,
                'sync_log_id' => $this->syncLogId,
                'records_updated' => count($results['success']),
                'errors' => count($results['errors']),
            ]);
        } catch (Exception $e) {
            $syncLog->markAsFailed($e->getMessage());
            $this->notifyProgress($shop, $syncLog, 0, "Import failed: {$e->getMessage()}");

            Log::error('Import job failed', [
                'shop_id' => $this->shopId,
                'sync_log_id' => $this->syncLogId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Read sheet data with pagination
     */
    protected function readSheetData(GoogleSheetsService $sheetsService, string $sheetId, DataTransformer $transformer): array
    {
        $columnMappings = $transformer->getFieldMappings();
        $columns = array_values($columnMappings);
        
        if (empty($columns)) {
            throw new Exception('No field mappings configured');
        }

        $firstCol = min($columns);
        $lastCol = max($columns);
        $headerRange = '1:1';
        $dataRange = "{$firstCol}2:{$lastCol}";

        // Read headers
        $headerResult = $sheetsService->readSheet($sheetId, $headerRange, null, 1, 1);
        $headers = $headerResult['data'][0] ?? [];

        // Read data with pagination
        $allData = [];
        $page = 1;
        $hasMore = true;

        while ($hasMore) {
            $result = $sheetsService->readSheet($sheetId, $dataRange, null, $page, 1000);
            $allData = array_merge($allData, $result['data']);
            $hasMore = $result['has_more'] ?? false;
            $page++;
        }

        // Map rows to column headers
        $mappedData = [];
        foreach ($allData as $row) {
            $mappedRow = [];
            foreach ($headers as $index => $header) {
                $mappedRow[trim($header)] = $row[$index] ?? '';
            }
            $mappedData[] = $mappedRow;
        }

        return $mappedData;
    }

    /**
     * Generate preview of changes
     */
    protected function generatePreview(array $shopifyData, InventoryService $inventoryService): array
    {
        $preview = [];

        foreach ($shopifyData as $index => $item) {
            $previewItem = [
                'row' => $index + 1,
                'changes' => [],
                'current' => [],
                'new' => $item,
            ];

            // Get current data from Shopify if variant ID is available
            if (isset($item['id']) || isset($item['variant_id'])) {
                $variantId = $item['id'] ?? $item['variant_id'];
                try {
                    $currentData = $inventoryService->getVariantData($variantId);
                    $previewItem['current'] = $currentData;

                    // Compare and identify changes
                    foreach ($item as $field => $newValue) {
                        $currentValue = $currentData[$field] ?? null;
                        if ($currentValue != $newValue) {
                            $previewItem['changes'][$field] = [
                                'current' => $currentValue,
                                'new' => $newValue,
                            ];
                        }
                    }
                } catch (Exception $e) {
                    $previewItem['error'] = $e->getMessage();
                }
            }

            $preview[] = $previewItem;
        }

        return [
            'preview' => $preview,
            'total_rows' => count($shopifyData),
            'rows_with_changes' => count(array_filter($preview, fn($item) => !empty($item['changes']))),
        ];
    }

    /**
     * Perform dry run (validate without applying)
     */
    protected function performDryRun(array $shopifyData, InventoryService $inventoryService): array
    {
        $results = [
            'valid' => [],
            'invalid' => [],
            'errors' => [],
        ];

        foreach ($shopifyData as $index => $item) {
            $rowNumber = $index + 1;
            $rowErrors = [];

            // Validate variant ID exists
            if (isset($item['id']) || isset($item['variant_id'])) {
                $variantId = $item['id'] ?? $item['variant_id'];
                try {
                    $currentData = $inventoryService->getVariantData($variantId);
                    if (!$currentData) {
                        $rowErrors[] = "Variant ID {$variantId} not found";
                    }
                } catch (Exception $e) {
                    $rowErrors[] = "Error validating variant: {$e->getMessage()}";
                }
            } else {
                $rowErrors[] = 'Missing variant ID';
            }

            // Validate data types
            if (isset($item['price']) && (!is_numeric($item['price']) || $item['price'] < 0)) {
                $rowErrors[] = 'Invalid price value';
            }

            if (isset($item['inventoryQuantity']) && (!is_numeric($item['inventoryQuantity']) || $item['inventoryQuantity'] < 0)) {
                $rowErrors[] = 'Invalid inventory quantity';
            }

            if (empty($rowErrors)) {
                $results['valid'][] = [
                    'row' => $rowNumber,
                    'data' => $item,
                ];
            } else {
                $results['invalid'][] = [
                    'row' => $rowNumber,
                    'data' => $item,
                    'errors' => $rowErrors,
                ];
                $results['errors'] = array_merge($results['errors'], $rowErrors);
            }
        }

        return $results;
    }

    /**
     * Update Shopify with transformed data
     */
    protected function updateShopify(
        array $shopifyData,
        InventoryService $inventoryService,
        SyncManager $syncManager
    ): array {
        $inventoryUpdates = [];
        $variantUpdates = [];

        foreach ($shopifyData as $item) {
            // Inventory update
            if (isset($item['variant_inventory_quantity']) || isset($item['inventoryQuantity'])) {
                if (isset($item['inventoryItemId']) && isset($item['locationId'])) {
                    $inventoryUpdates[] = [
                        'locationId' => $item['locationId'],
                        'inventoryItemId' => $item['inventoryItemId'],
                        'quantityDelta' => (int) ($item['variant_inventory_quantity'] ?? $item['inventoryQuantity'] ?? 0),
                    ];
                }
            }

            // Variant update
            if (isset($item['id']) || isset($item['variant_id'])) {
                $variantUpdate = [];
                if (isset($item['id'])) {
                    $variantUpdate['id'] = $item['id'];
                } elseif (isset($item['variant_id'])) {
                    $variantUpdate['id'] = $item['variant_id'];
                }

                if (isset($item['variant_price']) || isset($item['price'])) {
                    $variantUpdate['price'] = $item['variant_price'] ?? $item['price'];
                }
                if (isset($item['variant_sku']) || isset($item['sku'])) {
                    $variantUpdate['sku'] = $item['variant_sku'] ?? $item['sku'];
                }
                if (isset($item['variant_cost']) || isset($item['cost'])) {
                    $variantUpdate['cost'] = $item['variant_cost'] ?? $item['cost'];
                }

                if (!empty($variantUpdate)) {
                    $variantUpdates[] = $variantUpdate;
                }
            }
        }

        $results = [
            'success' => [],
            'errors' => [],
        ];

        // Update inventory
        if (!empty($inventoryUpdates)) {
            $inventoryResult = $inventoryService->bulkUpdateInventoryLevels($inventoryUpdates);
            $results['success'] = array_merge($results['success'], $inventoryResult['success'] ?? []);
            $results['errors'] = array_merge($results['errors'], $inventoryResult['errors'] ?? []);
        }

        // Update variants
        if (!empty($variantUpdates)) {
            $variantResult = $inventoryService->bulkUpdateVariants($variantUpdates);
            $results['success'] = array_merge($results['success'], $variantResult['success'] ?? []);
            $results['errors'] = array_merge($results['errors'], $variantResult['errors'] ?? []);
        }

        return $results;
    }

    /**
     * Store preview data in cache
     */
    protected function storePreviewData(array $data, SyncLog $syncLog): void
    {
        $cacheKey = "import_preview:{$this->syncLogId}";
        Cache::put($cacheKey, $data, now()->addDays(7));
    }

    /**
     * Store error report
     */
    protected function storeErrorReport(array $errors, SyncLog $syncLog): void
    {
        $cacheKey = "import_errors:{$this->syncLogId}";
        Cache::put($cacheKey, $errors, now()->addDays(7));
    }

    /**
     * Notify progress
     */
    protected function notifyProgress(User $shop, SyncLog $syncLog, int $processed, string $message): void
    {
        try {
            $shop->notify(new SyncProgressNotification($syncLog, $processed, $message));
        } catch (Exception $e) {
            Log::warning('Failed to send progress notification', [
                'shop_id' => $this->shopId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle job failure
     */
    public function failed(?Exception $exception): void
    {
        $syncLog = SyncLog::find($this->syncLogId);
        if ($syncLog) {
            $syncLog->markAsFailed($exception?->getMessage() ?? 'Job failed');
        }

        Log::error('Import job failed permanently', [
            'shop_id' => $this->shopId,
            'sync_log_id' => $this->syncLogId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
