<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\GoogleSheetsService;
use App\Services\DataTransformer;
use App\Services\InventoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

class InventoryLevelsUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // 5 minutes
    public int $tries = 3;
    public int $backoff = 30; // 30 seconds

    protected int $shopId;
    protected array $webhookData;
    protected int $debounceDelay = 5; // seconds

    /**
     * Create a new job instance.
     */
    public function __construct(int $shopId, array $webhookData)
    {
        $this->shopId = $shopId;
        $this->webhookData = $webhookData;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $shop = User::find($this->shopId);
        if (!$shop) {
            Log::error('Shop not found for webhook job', ['shop_id' => $this->shopId]);
            return;
        }

        // Check if Google Sheets is connected
        $connection = $shop->googleSheetsConnection;
        if (!$connection || !$connection->hasValidTokens() || !$connection->sheet_id) {
            Log::info('Google Sheets not connected, skipping webhook update', [
                'shop_id' => $this->shopId,
            ]);
            return;
        }

        try {
            // Debounce: Check if there's a pending update for this inventory item
            $inventoryItemId = $this->webhookData['inventory_item_id'] ?? null;
            if ($inventoryItemId) {
                $debounceKey = "webhook_debounce:{$this->shopId}:{$inventoryItemId}";
                
                if (Cache::has($debounceKey)) {
                    // Update existing debounce entry instead of processing immediately
                    Cache::put($debounceKey, $this->webhookData, now()->addSeconds($this->debounceDelay));
                    Log::debug('Webhook debounced', [
                        'shop_id' => $this->shopId,
                        'inventory_item_id' => $inventoryItemId,
                    ]);
                    return;
                }

                // Set debounce key
                Cache::put($debounceKey, $this->webhookData, now()->addSeconds($this->debounceDelay));
            }

            // Process the update
            $this->processInventoryUpdate($shop, $connection, $this->webhookData);

            Log::info('Webhook inventory update processed', [
                'shop_id' => $this->shopId,
                'inventory_item_id' => $inventoryItemId,
            ]);
        } catch (Exception $e) {
            Log::error('Error processing webhook inventory update', [
                'shop_id' => $this->shopId,
                'error' => $e->getMessage(),
                'webhook_data' => $this->webhookData,
            ]);

            throw $e;
        }
    }

    /**
     * Process inventory level update
     */
    protected function processInventoryUpdate(User $shop, $connection, array $webhookData): void
    {
        $inventoryItemId = $webhookData['inventory_item_id'] ?? null;
        $locationId = $webhookData['location_id'] ?? null;
        $available = $webhookData['available'] ?? null;

        if (!$inventoryItemId || !$locationId || $available === null) {
            throw new Exception('Missing required webhook data');
        }

        // Fetch variant data from Shopify
        $inventoryService = new InventoryService($shop);
        
        // Get variant by inventory item ID
        $variant = $this->getVariantByInventoryItem($inventoryService, $inventoryItemId);
        
        if (!$variant) {
            Log::warning('Variant not found for inventory item', [
                'shop_id' => $this->shopId,
                'inventory_item_id' => $inventoryItemId,
            ]);
            return;
        }

        // Prepare data for sheet update
        $sheetData = [
            [
                'variant_sku' => $variant['sku'] ?? '',
                'variant_inventory_quantity' => $available,
                'variant_price' => $variant['price'] ?? '0.00',
            ],
        ];

        // Transform to sheet format
        $transformer = new DataTransformer($shop);
        $transformedData = $transformer->transformShopifyToSheet($sheetData);
        $columnMappings = $transformer->getFieldMappings();

        // Find and update the row in Google Sheet
        $sheetsService = new GoogleSheetsService();
        $sheetsService->setConnection($shop);

        $this->updateSheetRow($sheetsService, $connection->sheet_id, $variant['sku'] ?? '', $transformedData[0] ?? [], $columnMappings);
    }

    /**
     * Get variant by inventory item ID
     */
    protected function getVariantByInventoryItem(InventoryService $inventoryService, string $inventoryItemId): ?array
    {
        try {
            // Query GraphQL to find variant by inventory item ID
            $query = <<<'GRAPHQL'
                query getVariantByInventoryItem($inventoryItemId: ID!) {
                    inventoryItem(id: $inventoryItemId) {
                        id
                        variant {
                            id
                            title
                            sku
                            price
                            inventoryQuantity
                        }
                    }
                }
            GRAPHQL;

            $response = $inventoryService->executeGraphQLQuery($query, [
                'inventoryItemId' => $inventoryItemId,
            ]);

            return $response['data']['inventoryItem']['variant'] ?? null;
        } catch (Exception $e) {
            Log::error('Error fetching variant by inventory item', [
                'inventory_item_id' => $inventoryItemId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Update specific row in Google Sheet
     */
    protected function updateSheetRow(
        GoogleSheetsService $sheetsService,
        string $sheetId,
        string $sku,
        array $rowData,
        array $columnMappings
    ): void {
        try {
            // Find row by SKU
            $rowNumber = $this->findRowBySku($sheetsService, $sheetId, $sku);
            
            if (!$rowNumber) {
                Log::warning('Row not found in sheet for SKU', [
                    'shop_id' => $this->shopId,
                    'sku' => $sku,
                ]);
                return;
            }

            // Prepare update data
            $columns = array_values($columnMappings);
            if (empty($columns)) {
                $columns = array_keys($rowData);
            }
            sort($columns);

            $updateRow = [];
            foreach ($columns as $col) {
                $updateRow[] = $rowData[$col] ?? '';
            }

            // Update the row
            $firstCol = min($columns);
            $lastCol = max($columns);
            $range = "{$firstCol}{$rowNumber}:{$lastCol}{$rowNumber}";
            
            $sheetsService->writeSheet($sheetId, $range, [$updateRow]);

            Log::debug('Sheet row updated via webhook', [
                'shop_id' => $this->shopId,
                'sku' => $sku,
                'row' => $rowNumber,
            ]);
        } catch (Exception $e) {
            Log::error('Error updating sheet row', [
                'shop_id' => $this->shopId,
                'sku' => $sku,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Find row number by SKU
     */
    protected function findRowBySku(GoogleSheetsService $sheetsService, string $sheetId, string $sku): ?int
    {
        try {
            // Read sheet to find SKU column and row
            $transformer = new DataTransformer(User::find($this->shopId));
            $columnMappings = $transformer->getFieldMappings();
            
            $skuColumn = $columnMappings['variant_sku'] ?? 'B'; // Default to column B
            
            // Read column to find SKU
            $result = $sheetsService->readSheet($sheetId, "{$skuColumn}:{$skuColumn}", null, 1, 1000);
            
            $rows = $result['data'] ?? [];
            foreach ($rows as $index => $row) {
                if (isset($row[0]) && trim($row[0]) === trim($sku)) {
                    return $index + 1; // +1 because sheets are 1-indexed
                }
            }

            return null;
        } catch (Exception $e) {
            Log::error('Error finding row by SKU', [
                'shop_id' => $this->shopId,
                'sku' => $sku,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Handle job failure
     */
    public function failed(?Exception $exception): void
    {
        Log::error('Webhook inventory update job failed permanently', [
            'shop_id' => $this->shopId,
            'error' => $exception?->getMessage(),
            'webhook_data' => $this->webhookData,
        ]);
    }
}
