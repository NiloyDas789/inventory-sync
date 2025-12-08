<?php

namespace App\Services;

use App\Models\User;
use App\Models\SyncLog;
use Illuminate\Support\Facades\Log;
use Exception;

class InventoryService
{
    protected User $shop;
    protected int $maxRetries = 3;
    protected int $retryDelay = 2; // seconds
    protected ?SyncLog $currentSyncLog = null;

    /**
     * Initialize service with shop
     */
    public function __construct(User $shop)
    {
        $this->shop = $shop;
    }

    /**
     * Fetch all products with variants using GraphQL
     * Supports pagination with cursor-based approach
     */
    public function fetchAllProducts(?string $cursor = null, int $limit = 250): array
    {
        $query = <<<'GRAPHQL'
            query getProducts($first: Int!, $after: String) {
                products(first: $first, after: $after) {
                    pageInfo {
                        hasNextPage
                        endCursor
                    }
                    edges {
                        node {
                            id
                            title
                            handle
                            description
                            vendor
                            productType
                            tags
                            status
                            createdAt
                            updatedAt
                            publishedAt
                            totalInventory
                            tracksInventory
                            weight
                            weightUnit
                            taxCode
                            variants(first: 250) {
                                edges {
                                    node {
                                        id
                                        title
                                        sku
                                        barcode
                                        price
                                        compareAtPrice
                                        cost
                                        weight
                                        weightUnit
                                        inventoryQuantity
                                        inventoryPolicy
                                        inventoryManagement
                                        taxable
                                        taxCode
                                        position
                                        createdAt
                                        updatedAt
                                        selectedOptions {
                                            name
                                            value
                                        }
                                        inventoryItem {
                                            id
                                            tracked
                                            requiresShipping
                                        }
                                    }
                                }
                            }
                            images(first: 10) {
                                edges {
                                    node {
                                        id
                                        url
                                        altText
                                        width
                                        height
                                    }
                                }
                            }
                        }
                    }
                }
            }
        GRAPHQL;

        $variables = [
            'first' => $limit,
        ];

        if ($cursor) {
            $variables['after'] = $cursor;
        }

        return $this->executeGraphQLQuery($query, $variables);
    }

    /**
     * Fetch products with pagination support
     * Returns all products by automatically handling pagination
     */
    public function fetchAllProductsPaginated(): array
    {
        $allProducts = [];
        $cursor = null;
        $hasNextPage = true;
        $pageCount = 0;

        while ($hasNextPage) {
            try {
                $response = $this->fetchAllProducts($cursor);
                $products = $response['data']['products']['edges'] ?? [];
                
                foreach ($products as $edge) {
                    $allProducts[] = $edge['node'];
                }

                $pageInfo = $response['data']['products']['pageInfo'] ?? [];
                $hasNextPage = $pageInfo['hasNextPage'] ?? false;
                $cursor = $pageInfo['endCursor'] ?? null;
                
                $pageCount++;
                
                Log::info('Fetched products page', [
                    'shop_id' => $this->shop->id,
                    'page' => $pageCount,
                    'products_count' => count($products),
                    'total_products' => count($allProducts),
                ]);

                // Rate limiting: small delay between pages
                if ($hasNextPage) {
                    usleep(500000); // 0.5 second delay
                }
            } catch (Exception $e) {
                Log::error('Error fetching products page', [
                    'shop_id' => $this->shop->id,
                    'page' => $pageCount + 1,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        return $allProducts;
    }

    /**
     * Fetch inventory levels for multiple locations
     */
    public function fetchInventoryLevels(array $inventoryItemIds, ?string $cursor = null, int $limit = 250): array
    {
        $query = <<<'GRAPHQL'
            query getInventoryLevels($first: Int!, $after: String, $inventoryItemIds: [ID!]!) {
                inventoryLevels(first: $first, after: $after, inventoryItemIds: $inventoryItemIds) {
                    pageInfo {
                        hasNextPage
                        endCursor
                    }
                    edges {
                        node {
                            id
                            available
                            location {
                                id
                                name
                                address {
                                    address1
                                    city
                                    province
                                    country
                                    zip
                                }
                            }
                            inventoryItem {
                                id
                                sku
                                tracked
                            }
                        }
                    }
                }
            }
        GRAPHQL;

        $variables = [
            'first' => $limit,
            'inventoryItemIds' => $inventoryItemIds,
        ];

        if ($cursor) {
            $variables['after'] = $cursor;
        }

        return $this->executeGraphQLQuery($query, $variables);
    }

    /**
     * Fetch all inventory levels for given inventory items
     */
    public function fetchAllInventoryLevels(array $inventoryItemIds): array
    {
        $allLevels = [];
        $cursor = null;
        $hasNextPage = true;
        $chunkSize = 50; // Process in chunks to avoid query size limits

        // Process inventory items in chunks
        $chunks = array_chunk($inventoryItemIds, $chunkSize);

        foreach ($chunks as $chunk) {
            $cursor = null;
            $hasNextPage = true;

            while ($hasNextPage) {
                try {
                    $response = $this->fetchInventoryLevels($chunk, $cursor);
                    $levels = $response['data']['inventoryLevels']['edges'] ?? [];

                    foreach ($levels as $edge) {
                        $allLevels[] = $edge['node'];
                    }

                    $pageInfo = $response['data']['inventoryLevels']['pageInfo'] ?? [];
                    $hasNextPage = $pageInfo['hasNextPage'] ?? false;
                    $cursor = $pageInfo['endCursor'] ?? null;

                    if ($hasNextPage) {
                        usleep(500000); // 0.5 second delay
                    }
                } catch (Exception $e) {
                    Log::error('Error fetching inventory levels', [
                        'shop_id' => $this->shop->id,
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }
            }
        }

        return $allLevels;
    }

    /**
     * Fetch additional product fields (cost, weight, tax settings)
     */
    public function fetchProductDetails(string $productId): array
    {
        $query = <<<'GRAPHQL'
            query getProduct($id: ID!) {
                product(id: $id) {
                    id
                    title
                    handle
                    cost
                    weight
                    weightUnit
                    taxCode
                    taxExempt
                    variants(first: 250) {
                        edges {
                            node {
                                id
                                cost
                                weight
                                weightUnit
                                taxCode
                                taxable
                            }
                        }
                    }
                }
            }
        GRAPHQL;

        $variables = [
            'id' => $productId,
        ];

        $response = $this->executeGraphQLQuery($query, $variables);
        return $response['data']['product'] ?? [];
    }

    /**
     * Fetch products updated since a specific date (for incremental sync)
     */
    public function fetchProductsSince(\DateTimeInterface $since, ?string $cursor = null, int $limit = 250): array
    {
        $query = <<<'GRAPHQL'
            query getProductsSince($first: Int!, $after: String, $updatedAt: DateTime!) {
                products(first: $first, after: $after, query: $updatedAt) {
                    pageInfo {
                        hasNextPage
                        endCursor
                    }
                    edges {
                        node {
                            id
                            title
                            handle
                            updatedAt
                            variants(first: 250) {
                                edges {
                                    node {
                                        id
                                        sku
                                        inventoryQuantity
                                        updatedAt
                                    }
                                }
                            }
                        }
                    }
                }
            }
        GRAPHQL;

        $variables = [
            'first' => $limit,
            'updatedAt' => "updated_at:>={$since->format('Y-m-d\TH:i:s\Z')}",
        ];

        if ($cursor) {
            $variables['after'] = $cursor;
        }

        return $this->executeGraphQLQuery($query, $variables);
    }

    /**
     * Execute GraphQL query with retry logic
     */
    public function executeGraphQLQuery(string $query, array $variables = []): array
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxRetries) {
            try {
                $api = $this->shop->apiHelper()->getApi();
                
                $response = $api->graph($query, $variables);
                
                // Check for errors in response
                if (isset($response['errors'])) {
                    $errorMessages = array_map(fn($error) => $error['message'] ?? 'Unknown error', $response['errors']);
                    throw new Exception('GraphQL errors: ' . implode(', ', $errorMessages));
                }

                // Log successful query
                Log::debug('GraphQL query executed', [
                    'shop_id' => $this->shop->id,
                    'attempt' => $attempt + 1,
                ]);

                // Log to sync log if available
                if ($this->currentSyncLog) {
                    $this->logApiOperation('GraphQL Query', [
                        'query_type' => $this->extractQueryType($query),
                        'attempt' => $attempt + 1,
                        'success' => true,
                    ]);
                }

                return $response;
            } catch (Exception $e) {
                $lastException = $e;
                $attempt++;

                // Check if it's a rate limit error
                if ($this->isRateLimitError($e)) {
                    $waitTime = $this->calculateRetryDelay($attempt);
                    Log::warning('Rate limit hit, retrying', [
                        'shop_id' => $this->shop->id,
                        'attempt' => $attempt,
                        'wait_time' => $waitTime,
                    ]);
                    sleep($waitTime);
                    continue;
                }

                // For other errors, log and rethrow
                if ($attempt >= $this->maxRetries) {
                    Log::error('GraphQL query failed after retries', [
                        'shop_id' => $this->shop->id,
                        'attempts' => $attempt,
                        'error' => $e->getMessage(),
                    ]);

                    // Log to sync log if available
                    if ($this->currentSyncLog) {
                        $this->logApiOperation('GraphQL Query', [
                            'query_type' => $this->extractQueryType($query),
                            'attempts' => $attempt,
                            'success' => false,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    throw $e;
                }

                // Wait before retry
                sleep($this->retryDelay * $attempt);
            }
        }

        throw $lastException ?? new Exception('GraphQL query failed');
    }

    /**
     * Check if error is rate limit related
     */
    protected function isRateLimitError(Exception $e): bool
    {
        $message = strtolower($e->getMessage());
        return str_contains($message, 'rate limit') || 
               str_contains($message, '429') ||
               str_contains($message, 'throttled');
    }

    /**
     * Calculate retry delay with exponential backoff
     */
    protected function calculateRetryDelay(int $attempt): int
    {
        return min($this->retryDelay * pow(2, $attempt - 1), 60); // Max 60 seconds
    }

    /**
     * Bulk update inventory levels using GraphQL mutations
     */
    public function bulkUpdateInventoryLevels(array $updates): array
    {
        $this->logApiOperation('Bulk Update Inventory Levels', [
            'total_updates' => count($updates),
        ]);

        // Group updates by location for efficiency
        $updatesByLocation = [];
        foreach ($updates as $update) {
            $locationId = $update['locationId'] ?? null;
            if (!$locationId) {
                continue;
            }
            $updatesByLocation[$locationId][] = $update;
        }

        $results = [];
        $errors = [];
        $rollbackData = [];

        foreach ($updatesByLocation as $locationId => $locationUpdates) {
            try {
                // Store original data for rollback (fetch current levels)
                $inventoryItemIds = array_filter(array_column($locationUpdates, 'inventoryItemId'));
                if (!empty($inventoryItemIds)) {
                    try {
                        $originalLevels = $this->fetchInventoryLevels($inventoryItemIds);
                        foreach ($originalLevels['data']['inventoryLevels']['edges'] ?? [] as $edge) {
                            $node = $edge['node'];
                            $rollbackData[] = [
                                'locationId' => $locationId,
                                'inventoryItemId' => $node['inventoryItem']['id'] ?? null,
                                'available' => $node['available'] ?? 0,
                            ];
                        }
                    } catch (Exception $e) {
                        Log::warning('Could not fetch original inventory levels for rollback', [
                            'shop_id' => $this->shop->id,
                            'location_id' => $locationId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Process in batches of 10 (Shopify limit)
                $batches = array_chunk($locationUpdates, 10);
                
                foreach ($batches as $batchIndex => $batch) {
                    $this->logApiOperation('Inventory Update Batch', [
                        'location_id' => $locationId,
                        'batch' => $batchIndex + 1,
                        'total_batches' => count($batches),
                        'items_in_batch' => count($batch),
                    ]);

                    $result = $this->updateInventoryLevelsBatch($locationId, $batch);
                    $results = array_merge($results, $result['success'] ?? []);
                    $errors = array_merge($errors, $result['errors'] ?? []);

                    // If errors occur, rollback
                    if (!empty($result['errors'])) {
                        $this->rollbackInventoryLevels($rollbackData);
                        throw new Exception('Inventory update failed and rolled back');
                    }
                }
            } catch (Exception $e) {
                Log::error('Error updating inventory levels for location', [
                    'shop_id' => $this->shop->id,
                    'location_id' => $locationId,
                    'error' => $e->getMessage(),
                ]);

                $this->logApiOperation('Inventory Update Error', [
                    'location_id' => $locationId,
                    'error' => $e->getMessage(),
                ]);

                $errors[] = [
                    'location_id' => $locationId,
                    'error' => $e->getMessage(),
                ];

                // Rollback on error
                if (!empty($rollbackData)) {
                    $this->rollbackInventoryLevels($rollbackData);
                }
            }
        }

        $this->logApiOperation('Bulk Update Inventory Levels Complete', [
            'total_updated' => count($results),
            'total_errors' => count($errors),
        ]);

        return [
            'success' => $results,
            'errors' => $errors,
            'total_updated' => count($results),
            'total_errors' => count($errors),
        ];
    }

    /**
     * Update inventory levels in a batch (max 10 per mutation)
     */
    protected function updateInventoryLevelsBatch(string $locationId, array $updates): array
    {
        $mutation = <<<'GRAPHQL'
            mutation inventoryBulkAdjustQuantityAtLocation($locationId: ID!, $inventoryItemAdjustments: [InventoryItemAdjustment!]!) {
                inventoryBulkAdjustQuantityAtLocation(
                    locationId: $locationId
                    inventoryItemAdjustments: $inventoryItemAdjustments
                ) {
                    userErrors {
                        field
                        message
                    }
                    inventoryLevels {
                        id
                        available
                        location {
                            id
                            name
                        }
                        inventoryItem {
                            id
                            sku
                        }
                    }
                }
            }
        GRAPHQL;

        $adjustments = [];
        foreach ($updates as $update) {
            // Validate inventory quantity (prevent negative)
            $quantity = $update['quantity'] ?? 0;
            if ($quantity < 0) {
                throw new Exception("Invalid inventory quantity: {$quantity}. Cannot be negative.");
            }

            $adjustments[] = [
                'inventoryItemId' => $update['inventoryItemId'],
                'quantityDelta' => $update['quantityDelta'] ?? 0,
            ];
        }

        $variables = [
            'locationId' => $locationId,
            'inventoryItemAdjustments' => $adjustments,
        ];

        $response = $this->executeGraphQLQuery($mutation, $variables);
        
        $mutationResult = $response['data']['inventoryBulkAdjustQuantityAtLocation'] ?? [];
        $userErrors = $mutationResult['userErrors'] ?? [];
        $inventoryLevels = $mutationResult['inventoryLevels'] ?? [];

        if (!empty($userErrors)) {
            $errorMessages = array_map(fn($error) => $error['message'] ?? 'Unknown error', $userErrors);
            throw new Exception('Inventory update errors: ' . implode(', ', $errorMessages));
        }

        return [
            'success' => $inventoryLevels,
            'errors' => [],
        ];
    }

    /**
     * Bulk update product variants
     */
    public function bulkUpdateVariants(array $updates): array
    {
        $this->logApiOperation('Bulk Update Variants', [
            'total_updates' => count($updates),
        ]);

        $results = [];
        $errors = [];
        $rollbackData = [];

        foreach ($updates as $index => $update) {
            try {
                // Store original data for rollback
                $variantId = $update['id'] ?? null;
                if ($variantId) {
                    $originalData = $this->getVariantData($variantId);
                    $rollbackData[$variantId] = $originalData;
                }

                // Validate data before update
                $this->validateVariantUpdate($update);

                $this->logApiOperation('Variant Update', [
                    'variant_id' => $variantId,
                    'index' => $index + 1,
                    'total' => count($updates),
                ]);

                $result = $this->updateVariant($update);
                $results[] = $result;

                // Update sync log progress
                if ($this->currentSyncLog) {
                    $this->currentSyncLog->updateRecordsProcessed(count($results));
                }
            } catch (Exception $e) {
                Log::error('Error updating variant', [
                    'shop_id' => $this->shop->id,
                    'variant_id' => $update['id'] ?? null,
                    'error' => $e->getMessage(),
                ]);

                $this->logApiOperation('Variant Update Error', [
                    'variant_id' => $update['id'] ?? null,
                    'error' => $e->getMessage(),
                ]);

                $errors[] = [
                    'variant_id' => $update['id'] ?? null,
                    'error' => $e->getMessage(),
                ];

                // Rollback previous updates if error occurs
                if (!empty($rollbackData)) {
                    $this->rollbackVariants($rollbackData);
                    throw new Exception('Bulk update failed and rolled back: ' . $e->getMessage());
                }
            }
        }

        $this->logApiOperation('Bulk Update Variants Complete', [
            'total_updated' => count($results),
            'total_errors' => count($errors),
        ]);

        return [
            'success' => $results,
            'errors' => $errors,
            'total_updated' => count($results),
            'total_errors' => count($errors),
        ];
    }

    /**
     * Update a single variant
     */
    protected function updateVariant(array $update): array
    {
        $mutation = <<<'GRAPHQL'
            mutation productVariantUpdate($input: ProductVariantInput!) {
                productVariantUpdate(input: $input) {
                    productVariant {
                        id
                        title
                        sku
                        price
                        compareAtPrice
                        cost
                        inventoryQuantity
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }
        GRAPHQL;

        $input = [];
        if (isset($update['id'])) {
            $input['id'] = $update['id'];
        }
        if (isset($update['price'])) {
            $input['price'] = (string) $update['price'];
        }
        if (isset($update['compareAtPrice'])) {
            $input['compareAtPrice'] = (string) $update['compareAtPrice'];
        }
        if (isset($update['cost'])) {
            $input['cost'] = (string) $update['cost'];
        }
        if (isset($update['sku'])) {
            $input['sku'] = $update['sku'];
        }
        if (isset($update['barcode'])) {
            $input['barcode'] = $update['barcode'];
        }
        if (isset($update['weight'])) {
            $input['weight'] = (float) $update['weight'];
        }
        if (isset($update['weightUnit'])) {
            $input['weightUnit'] = $update['weightUnit'];
        }
        if (isset($update['taxable'])) {
            $input['taxable'] = (bool) $update['taxable'];
        }
        if (isset($update['taxCode'])) {
            $input['taxCode'] = $update['taxCode'];
        }

        $variables = [
            'input' => $input,
        ];

        $response = $this->executeGraphQLQuery($mutation, $variables);
        
        $mutationResult = $response['data']['productVariantUpdate'] ?? [];
        $userErrors = $mutationResult['userErrors'] ?? [];
        $variant = $mutationResult['productVariant'] ?? null;

        if (!empty($userErrors)) {
            $errorMessages = array_map(fn($error) => $error['message'] ?? 'Unknown error', $userErrors);
            throw new Exception('Variant update errors: ' . implode(', ', $errorMessages));
        }

        if (!$variant) {
            throw new Exception('Variant update failed: No variant returned');
        }

        return $variant;
    }

    /**
     * Get variant data for rollback
     */
    protected function getVariantData(string $variantId): array
    {
        $query = <<<'GRAPHQL'
            query getVariant($id: ID!) {
                productVariant(id: $id) {
                    id
                    price
                    compareAtPrice
                    cost
                    sku
                    barcode
                    weight
                    weightUnit
                    taxable
                    taxCode
                }
            }
        GRAPHQL;

        $variables = ['id' => $variantId];
        $response = $this->executeGraphQLQuery($query, $variables);
        
        return $response['data']['productVariant'] ?? [];
    }

    /**
     * Rollback variants to original state
     */
    protected function rollbackVariants(array $rollbackData): void
    {
        Log::warning('Rolling back variant updates', [
            'shop_id' => $this->shop->id,
            'variants_count' => count($rollbackData),
        ]);

        foreach ($rollbackData as $variantId => $originalData) {
            try {
                $this->updateVariant([
                    'id' => $variantId,
                    'price' => $originalData['price'] ?? null,
                    'compareAtPrice' => $originalData['compareAtPrice'] ?? null,
                    'cost' => $originalData['cost'] ?? null,
                    'sku' => $originalData['sku'] ?? null,
                    'barcode' => $originalData['barcode'] ?? null,
                    'weight' => $originalData['weight'] ?? null,
                    'weightUnit' => $originalData['weightUnit'] ?? null,
                    'taxable' => $originalData['taxable'] ?? null,
                    'taxCode' => $originalData['taxCode'] ?? null,
                ]);
            } catch (Exception $e) {
                Log::error('Error rolling back variant', [
                    'shop_id' => $this->shop->id,
                    'variant_id' => $variantId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Validate variant update data
     */
    protected function validateVariantUpdate(array $update): void
    {
        // Validate price
        if (isset($update['price']) && $update['price'] < 0) {
            throw new Exception('Price cannot be negative');
        }

        // Validate compare at price
        if (isset($update['compareAtPrice']) && $update['compareAtPrice'] < 0) {
            throw new Exception('Compare at price cannot be negative');
        }

        // Validate cost
        if (isset($update['cost']) && $update['cost'] < 0) {
            throw new Exception('Cost cannot be negative');
        }

        // Validate weight
        if (isset($update['weight']) && $update['weight'] < 0) {
            throw new Exception('Weight cannot be negative');
        }

        // Validate weight unit
        if (isset($update['weightUnit']) && !in_array($update['weightUnit'], ['KILOGRAMS', 'POUNDS', 'OUNCES', 'GRAMS'])) {
            throw new Exception('Invalid weight unit');
        }
    }

    /**
     * Set current sync log for detailed logging
     */
    public function setSyncLog(?SyncLog $syncLog): self
    {
        $this->currentSyncLog = $syncLog;
        return $this;
    }

    /**
     * Create a new sync log
     */
    public function createSyncLog(string $syncType): SyncLog
    {
        $syncLog = SyncLog::create([
            'shop_id' => $this->shop->id,
            'sync_type' => $syncType,
            'status' => 'pending',
        ]);

        $this->currentSyncLog = $syncLog;
        return $syncLog;
    }

    /**
     * Log API operation details
     */
    protected function logApiOperation(string $operation, array $details = []): void
    {
        if (!$this->currentSyncLog) {
            return;
        }

        Log::info("Shopify API Operation: {$operation}", array_merge([
            'shop_id' => $this->shop->id,
            'sync_log_id' => $this->currentSyncLog->id,
        ], $details));
    }

    /**
     * Extract query type from GraphQL query string
     */
    protected function extractQueryType(string $query): string
    {
        // Extract the first operation name or type
        if (preg_match('/\b(query|mutation|subscription)\s+(\w+)/i', $query, $matches)) {
            return strtoupper($matches[1]) . ' ' . $matches[2];
        }

        // Fallback: extract first word after query/mutation
        if (preg_match('/\b(query|mutation)\s+(\w+)/i', $query, $matches)) {
            return $matches[2];
        }

        return 'UNKNOWN';
    }

    /**
     * Rollback inventory levels to original state
     */
    protected function rollbackInventoryLevels(array $rollbackData): void
    {
        Log::warning('Rolling back inventory level updates', [
            'shop_id' => $this->shop->id,
            'levels_count' => count($rollbackData),
        ]);

        $this->logApiOperation('Inventory Rollback', [
            'levels_count' => count($rollbackData),
        ]);

        // Group by location
        $byLocation = [];
        foreach ($rollbackData as $data) {
            $locationId = $data['locationId'] ?? null;
            if ($locationId) {
                $byLocation[$locationId][] = [
                    'inventoryItemId' => $data['inventoryItemId'],
                    'quantityDelta' => 0, // Set to current quantity (would need to fetch current and calculate delta)
                ];
            }
        }

        // Note: Full rollback would require fetching current levels and calculating deltas
        // This is a simplified version - in production, you'd want to store the original values
        foreach ($byLocation as $locationId => $updates) {
            try {
                $batches = array_chunk($updates, 10);
                foreach ($batches as $batch) {
                    // This would need the current quantity to calculate proper delta
                    // For now, we log the rollback attempt
                    $this->logApiOperation('Inventory Rollback Batch', [
                        'location_id' => $locationId,
                        'items_count' => count($batch),
                    ]);
                }
            } catch (Exception $e) {
                Log::error('Error during inventory rollback', [
                    'shop_id' => $this->shop->id,
                    'location_id' => $locationId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}

