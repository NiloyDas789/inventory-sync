<?php

namespace App\Services;

use App\Models\User;
use App\Models\SyncLog;
use App\Models\GoogleSheetsConnection;
use App\Services\InventoryService;
use App\Services\GoogleSheetsService;
use App\Services\DataTransformer;
use Illuminate\Support\Facades\Log;
use Exception;

class SyncService
{
    protected User $shop;
    protected InventoryService $inventoryService;
    protected GoogleSheetsService $sheetsService;
    protected DataTransformer $transformer;
    protected ?SyncLog $syncLog = null;

    /**
     * Initialize sync service
     */
    public function __construct(User $shop)
    {
        $this->shop = $shop;
        $this->inventoryService = new InventoryService($shop);
        $this->sheetsService = new GoogleSheetsService();
        $this->transformer = new DataTransformer($shop);
    }

    /**
     * Sync products from Shopify to Google Sheets
     */
    public function syncProductsToSheets(string $syncType = 'products'): array
    {
        try {
            // Create sync log
            $this->syncLog = $this->inventoryService->createSyncLog($syncType);
            $this->syncLog->markAsStarted();
            $this->inventoryService->setSyncLog($this->syncLog);

            // Check Google Sheets connection
            $connection = $this->shop->googleSheetsConnection;
            if (!$connection || !$connection->hasValidTokens()) {
                throw new Exception('Google Sheets not connected');
            }

            if (!$connection->sheet_id) {
                throw new Exception('No Google Sheet configured');
            }

            // Set up services
            $this->sheetsService->setConnection($this->shop);

            // Fetch products from Shopify
            Log::info('Starting product sync from Shopify', [
                'shop_id' => $this->shop->id,
                'sync_log_id' => $this->syncLog->id,
            ]);

            $products = $this->inventoryService->fetchAllProductsPaginated();
            $this->syncLog->updateRecordsProcessed(count($products));

            // Transform products to sheet format
            $sheetData = $this->transformer->transformShopifyToSheet($products);

            // Get sheet name and column mappings
            $sheetName = $this->getSheetName();
            $columnMappings = $this->transformer->getFieldMappings();

            // Write to Google Sheets
            $this->writeToSheet($sheetData, $sheetName, $columnMappings);

            // Update last synced timestamp
            $connection->update(['last_synced_at' => now()]);

            // Mark as completed
            $this->syncLog->markAsCompleted(count($products));

            Log::info('Product sync completed', [
                'shop_id' => $this->shop->id,
                'sync_log_id' => $this->syncLog->id,
                'products_synced' => count($products),
            ]);

            return [
                'success' => true,
                'products_synced' => count($products),
                'sync_log_id' => $this->syncLog->id,
            ];
        } catch (Exception $e) {
            if ($this->syncLog) {
                $this->syncLog->markAsFailed($e->getMessage());
            }

            Log::error('Product sync failed', [
                'shop_id' => $this->shop->id,
                'sync_log_id' => $this->syncLog?->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Sync inventory from Shopify to Google Sheets
     */
    public function syncInventoryToSheets(string $syncType = 'inventory'): array
    {
        try {
            // Create sync log
            $this->syncLog = $this->inventoryService->createSyncLog($syncType);
            $this->syncLog->markAsStarted();
            $this->inventoryService->setSyncLog($this->syncLog);

            // Check Google Sheets connection
            $connection = $this->shop->googleSheetsConnection;
            if (!$connection || !$connection->hasValidTokens()) {
                throw new Exception('Google Sheets not connected');
            }

            if (!$connection->sheet_id) {
                throw new Exception('No Google Sheet configured');
            }

            // Set up services
            $this->sheetsService->setConnection($this->shop);

            // Fetch products with inventory
            $products = $this->inventoryService->fetchAllProductsPaginated();
            
            // Extract inventory data
            $inventoryData = [];
            foreach ($products as $product) {
                if (isset($product['variants']['edges'])) {
                    foreach ($product['variants']['edges'] as $variantEdge) {
                        $variant = $variantEdge['node'];
                        $inventoryData[] = [
                            'product_title' => $product['title'] ?? '',
                            'variant_title' => $variant['title'] ?? '',
                            'variant_sku' => $variant['sku'] ?? '',
                            'variant_inventory_quantity' => $variant['inventoryQuantity'] ?? 0,
                            'variant_price' => $variant['price'] ?? '0.00',
                        ];
                    }
                }
            }

            $this->syncLog->updateRecordsProcessed(count($inventoryData));

            // Transform to sheet format
            $sheetData = $this->transformer->transformShopifyToSheet($inventoryData);

            // Write to Google Sheets
            $sheetName = $this->getSheetName();
            $columnMappings = $this->transformer->getFieldMappings();
            $this->writeToSheet($sheetData, $sheetName, $columnMappings);

            // Update last synced timestamp
            $connection->update(['last_synced_at' => now()]);

            // Mark as completed
            $this->syncLog->markAsCompleted(count($inventoryData));

            return [
                'success' => true,
                'inventory_items_synced' => count($inventoryData),
                'sync_log_id' => $this->syncLog->id,
            ];
        } catch (Exception $e) {
            if ($this->syncLog) {
                $this->syncLog->markAsFailed($e->getMessage());
            }

            Log::error('Inventory sync failed', [
                'shop_id' => $this->shop->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Sync data from Google Sheets to Shopify
     */
    public function syncSheetsToShopify(string $syncType = 'import'): array
    {
        try {
            // Create sync log
            $this->syncLog = $this->inventoryService->createSyncLog($syncType);
            $this->syncLog->markAsStarted();
            $this->inventoryService->setSyncLog($this->syncLog);

            // Check Google Sheets connection
            $connection = $this->shop->googleSheetsConnection;
            if (!$connection || !$connection->hasValidTokens()) {
                throw new Exception('Google Sheets not connected');
            }

            if (!$connection->sheet_id) {
                throw new Exception('No Google Sheet configured');
            }

            // Set up services
            $this->sheetsService->setConnection($this->shop);

            // Read data from Google Sheets
            $sheetName = $this->getSheetName();
            $sheetData = $this->readFromSheet($sheetName);

            // Validate data
            $validation = $this->transformer->validateImportData($sheetData);
            
            if (!empty($validation['errors'])) {
                throw new Exception('Data validation failed: ' . json_encode($validation['errors']));
            }

            // Transform to Shopify format
            $shopifyData = $this->transformer->transformSheetToShopify($validation['valid']);

            // Update Shopify
            $results = $this->updateShopify($shopifyData);

            $this->syncLog->updateRecordsProcessed(count($results['success']));

            // Mark as completed
            $this->syncLog->markAsCompleted(count($results['success']));

            return [
                'success' => true,
                'records_updated' => count($results['success']),
                'errors' => $results['errors'],
                'sync_log_id' => $this->syncLog->id,
            ];
        } catch (Exception $e) {
            if ($this->syncLog) {
                $this->syncLog->markAsFailed($e->getMessage());
            }

            Log::error('Sheets to Shopify sync failed', [
                'shop_id' => $this->shop->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Full sync: bidirectional sync
     */
    public function fullSync(): array
    {
        try {
            // Create sync log
            $this->syncLog = $this->inventoryService->createSyncLog('full');
            $this->syncLog->markAsStarted();
            $this->inventoryService->setSyncLog($this->syncLog);

            // Sync Shopify to Sheets
            $exportResult = $this->syncProductsToSheets('full_export');
            
            // Sync Sheets to Shopify
            $importResult = $this->syncSheetsToShopify('full_import');

            // Mark as completed
            $this->syncLog->markAsCompleted(
                ($exportResult['products_synced'] ?? 0) + ($importResult['records_updated'] ?? 0)
            );

            return [
                'success' => true,
                'export' => $exportResult,
                'import' => $importResult,
                'sync_log_id' => $this->syncLog->id,
            ];
        } catch (Exception $e) {
            if ($this->syncLog) {
                $this->syncLog->markAsFailed($e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * Read data from Google Sheet
     */
    protected function readFromSheet(?string $sheetName = null): array
    {
        $connection = $this->shop->googleSheetsConnection;
        $sheetId = $connection->sheet_id;

        // Determine range based on column mappings
        $columnMappings = $this->transformer->getFieldMappings();
        $columns = array_values($columnMappings);
        if (empty($columns)) {
            throw new Exception('No field mappings configured');
        }

        // Get first and last column
        $firstCol = min($columns);
        $lastCol = max($columns);
        
        // Read header row and data
        $headerRange = $sheetName ? "{$sheetName}!1:1" : '1:1';
        $dataRange = $sheetName ? "{$sheetName}!{$firstCol}2:{$lastCol}" : "{$firstCol}2:{$lastCol}";

        // Read headers
        $headerResult = $this->sheetsService->readSheet($sheetId, $headerRange, $sheetName, 1, 1);
        $headers = $headerResult['data'][0] ?? [];

        // Read data with pagination
        $allData = [];
        $page = 1;
        $hasMore = true;

        while ($hasMore) {
            $result = $this->sheetsService->readSheet($sheetId, $dataRange, $sheetName, $page, 1000);
            $allData = array_merge($allData, $result['data']);
            $hasMore = $result['has_more'] ?? false;
            $page++;
        }

        // Map rows to column headers
        // Headers should match the sheet column names (A, B, C, etc. or actual column names)
        $mappedData = [];
        foreach ($allData as $row) {
            $mappedRow = [];
            foreach ($headers as $index => $header) {
                // Use header as key (could be column letter or name)
                $mappedRow[trim($header)] = $row[$index] ?? '';
            }
            $mappedData[] = $mappedRow;
        }

        return $mappedData;
    }

    /**
     * Write data to Google Sheet
     */
    protected function writeToSheet(array $sheetData, ?string $sheetName = null, array $columnMappings = []): void
    {
        $connection = $this->shop->googleSheetsConnection;
        $sheetId = $connection->sheet_id;

        if (empty($sheetData)) {
            return;
        }

        // Get column order from mappings (sheet columns like A, B, C)
        $columns = array_values($columnMappings);
        if (empty($columns)) {
            // Use first row to determine columns
            $columns = array_keys($sheetData[0] ?? []);
        }

        // Sort columns to maintain order
        sort($columns);

        // Prepare data rows
        $rows = [];
        
        // Header row - use Shopify field names as headers
        $reverseMapping = array_flip($columnMappings);
        $headerRow = [];
        foreach ($columns as $col) {
            $shopifyField = $reverseMapping[$col] ?? $col;
            // Format field name for display (e.g., variant_price -> Variant Price)
            $headerRow[] = ucwords(str_replace('_', ' ', $shopifyField));
        }
        $rows[] = $headerRow;

        // Data rows - ensure values are in correct column order
        foreach ($sheetData as $row) {
            $dataRow = [];
            foreach ($columns as $col) {
                $dataRow[] = $row[$col] ?? '';
            }
            $rows[] = $dataRow;
        }

        // Determine range using column letters
        $firstCol = min($columns);
        $lastCol = max($columns);
        $lastRow = count($rows);
        $range = "{$firstCol}1:{$lastCol}{$lastRow}";

        // Write in batches if large
        if (count($rows) > 1000) {
            $batches = array_chunk($rows, 1000);
            $startRow = 1;
            
            foreach ($batches as $batch) {
                $batchLastRow = $startRow + count($batch) - 1;
                $batchRange = "{$firstCol}{$startRow}:{$lastCol}{$batchLastRow}";
                $this->sheetsService->writeSheet($sheetId, $batchRange, $batch, $sheetName);
                $startRow += count($batch);
            }
        } else {
            $this->sheetsService->writeSheet($sheetId, $range, $rows, $sheetName);
        }
    }

    /**
     * Update Shopify with transformed data
     */
    protected function updateShopify(array $shopifyData): array
    {
        $inventoryUpdates = [];
        $variantUpdates = [];

        foreach ($shopifyData as $item) {
            // Determine update type based on available fields
            if (isset($item['variant_inventory_quantity']) || isset($item['inventoryQuantity'])) {
                // Inventory update
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
            $inventoryResult = $this->inventoryService->bulkUpdateInventoryLevels($inventoryUpdates);
            $results['success'] = array_merge($results['success'], $inventoryResult['success'] ?? []);
            $results['errors'] = array_merge($results['errors'], $inventoryResult['errors'] ?? []);
        }

        // Update variants
        if (!empty($variantUpdates)) {
            $variantResult = $this->inventoryService->bulkUpdateVariants($variantUpdates);
            $results['success'] = array_merge($results['success'], $variantResult['success'] ?? []);
            $results['errors'] = array_merge($results['errors'], $variantResult['errors'] ?? []);
        }

        return $results;
    }

    /**
     * Get sheet name from connection or default
     */
    protected function getSheetName(): ?string
    {
        // Could be configured per shop, for now return null (default sheet)
        return null;
    }

    /**
     * Get sync log
     */
    public function getSyncLog(): ?SyncLog
    {
        return $this->syncLog;
    }
}

