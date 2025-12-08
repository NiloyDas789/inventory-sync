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
use Exception;

class ExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600; // 1 hour
    public int $tries = 3;
    public int $backoff = 60; // 1 minute

    protected int $shopId;
    protected int $syncLogId;
    protected string $conflictResolution;
    protected array $options;

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
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $shop = User::find($this->shopId);
        if (!$shop) {
            Log::error('Shop not found for export job', ['shop_id' => $this->shopId]);
            return;
        }

        $syncLog = SyncLog::find($this->syncLogId);
        if (!$syncLog || $syncLog->shop_id !== $this->shopId) {
            Log::error('Sync log not found for export job', [
                'shop_id' => $this->shopId,
                'sync_log_id' => $this->syncLogId,
            ]);
            return;
        }

        try {
            $syncLog->markAsStarted();
            $this->notifyProgress($shop, $syncLog, 0, 'Starting export...');

            $inventoryService = new InventoryService($shop);
            $inventoryService->setSyncLog($syncLog);

            $sheetsService = new GoogleSheetsService();
            $sheetsService->setConnection($shop);

            $transformer = new DataTransformer($shop);
            $syncManager = new SyncManager($shop);

            // Determine strategy
            $strategy = $this->options['strategy'] ?? SyncManager::STRATEGY_FULL;

            // Fetch products in chunks
            $chunkSize = $this->options['chunk_size'] ?? 100;
            $allProducts = [];
            $cursor = null;
            $hasNextPage = true;
            $chunkIndex = 0;

            while ($hasNextPage) {
                try {
                    $response = $inventoryService->fetchAllProducts($cursor, 250);
                    $products = $response['data']['products']['edges'] ?? [];

                    foreach ($products as $edge) {
                        $allProducts[] = $edge['node'];
                    }

                    $pageInfo = $response['data']['products']['pageInfo'] ?? [];
                    $hasNextPage = $pageInfo['hasNextPage'] ?? false;
                    $cursor = $pageInfo['endCursor'] ?? null;

                    $chunkIndex++;
                    $processed = count($allProducts);
                    
                    // Update progress
                    $syncManager->updateProgress($this->syncLogId, $processed);
                    $this->notifyProgress($shop, $syncLog, $processed, "Processed {$processed} products...");

                    // Process in chunks to manage memory
                    if (count($allProducts) >= $chunkSize) {
                        $this->processChunk($allProducts, $transformer, $sheetsService, $syncLog);
                        $allProducts = []; // Clear memory
                    }

                    if ($hasNextPage) {
                        usleep(500000); // 0.5 second delay
                    }
                } catch (Exception $e) {
                    Log::error('Error fetching products chunk', [
                        'shop_id' => $this->shopId,
                        'sync_log_id' => $this->syncLogId,
                        'chunk' => $chunkIndex,
                        'error' => $e->getMessage(),
                    ]);

                    // Retry logic handled by queue
                    throw $e;
                }
            }

            // Process remaining products
            if (!empty($allProducts)) {
                $this->processChunk($allProducts, $transformer, $sheetsService, $syncLog);
            }

            // Update connection timestamp
            $connection = $shop->googleSheetsConnection;
            if ($connection) {
                $connection->update(['last_synced_at' => now()]);
            }

            $totalProcessed = $syncLog->records_processed;
            $syncLog->markAsCompleted($totalProcessed);
            $this->notifyProgress($shop, $syncLog, $totalProcessed, "Export completed: {$totalProcessed} products synced");

            Log::info('Export job completed', [
                'shop_id' => $this->shopId,
                'sync_log_id' => $this->syncLogId,
                'products_synced' => $totalProcessed,
            ]);
        } catch (Exception $e) {
            $syncLog->markAsFailed($e->getMessage());
            $this->notifyProgress($shop, $syncLog, 0, "Export failed: {$e->getMessage()}");

            Log::error('Export job failed', [
                'shop_id' => $this->shopId,
                'sync_log_id' => $this->syncLogId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Process a chunk of products
     */
    protected function processChunk(
        array $products,
        DataTransformer $transformer,
        GoogleSheetsService $sheetsService,
        SyncLog $syncLog
    ): void {
        try {
            // Transform products to sheet format
            $sheetData = $transformer->transformShopifyToSheet($products);
            $columnMappings = $transformer->getFieldMappings();

            // Get connection
            $shop = User::find($this->shopId);
            $connection = $shop->googleSheetsConnection;
            if (!$connection || !$connection->sheet_id) {
                throw new Exception('Google Sheets not configured');
            }

            // Write to sheet with error recovery
            $this->writeWithErrorRecovery(
                $sheetsService,
                $connection->sheet_id,
                $sheetData,
                $columnMappings,
                3 // max retries
            );

            // Update records processed
            $syncLog->updateRecordsProcessed($syncLog->records_processed + count($products));
        } catch (Exception $e) {
            Log::error('Error processing chunk', [
                'shop_id' => $this->shopId,
                'sync_log_id' => $this->syncLogId,
                'chunk_size' => count($products),
                'error' => $e->getMessage(),
            ]);

            // Continue processing other chunks even if one fails
            // Errors are logged and can be reviewed
        }
    }

    /**
     * Write to sheet with error recovery
     */
    protected function writeWithErrorRecovery(
        GoogleSheetsService $sheetsService,
        string $sheetId,
        array $sheetData,
        array $columnMappings,
        int $maxRetries = 3
    ): void {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxRetries) {
            try {
                if (empty($sheetData)) {
                    return;
                }

                // Get column order
                $columns = array_values($columnMappings);
                if (empty($columns)) {
                    $columns = array_keys($sheetData[0] ?? []);
                }
                sort($columns);

                // Prepare rows
                $rows = [];
                $reverseMapping = array_flip($columnMappings);
                $headerRow = [];
                foreach ($columns as $col) {
                    $shopifyField = $reverseMapping[$col] ?? $col;
                    $headerRow[] = ucwords(str_replace('_', ' ', $shopifyField));
                }
                $rows[] = $headerRow;

                foreach ($sheetData as $row) {
                    $dataRow = [];
                    foreach ($columns as $col) {
                        $dataRow[] = $row[$col] ?? '';
                    }
                    $rows[] = $dataRow;
                }

                // Write in batches
                $firstCol = min($columns);
                $lastCol = max($columns);
                $batchSize = 1000;

                if (count($rows) > $batchSize) {
                    $batches = array_chunk($rows, $batchSize);
                    $startRow = 1;

                    foreach ($batches as $batch) {
                        $batchLastRow = $startRow + count($batch) - 1;
                        $batchRange = "{$firstCol}{$startRow}:{$lastCol}{$batchLastRow}";
                        $sheetsService->writeSheet($sheetId, $batchRange, $batch);
                        $startRow += count($batch);
                    }
                } else {
                    $lastRow = count($rows);
                    $range = "{$firstCol}1:{$lastCol}{$lastRow}";
                    $sheetsService->writeSheet($sheetId, $range, $rows);
                }

                return; // Success
            } catch (Exception $e) {
                $attempt++;
                $lastException = $e;

                Log::warning('Sheet write attempt failed', [
                    'shop_id' => $this->shopId,
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < $maxRetries) {
                    sleep(pow(2, $attempt)); // Exponential backoff
                }
            }
        }

        throw $lastException ?? new Exception('Failed to write to sheet after retries');
    }

    /**
     * Notify progress
     */
    protected function notifyProgress(User $shop, SyncLog $syncLog, int $processed, string $message): void
    {
        try {
            $shop->notify(new SyncProgressNotification($syncLog, $processed, $message));
        } catch (Exception $e) {
            // Don't fail the job if notification fails
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

        Log::error('Export job failed permanently', [
            'shop_id' => $this->shopId,
            'sync_log_id' => $this->syncLogId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
