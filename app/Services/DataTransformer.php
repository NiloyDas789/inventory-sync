<?php

namespace App\Services;

use App\Models\User;
use App\Models\SyncFieldMapping;
use Carbon\Carbon;
use Exception;

class DataTransformer
{
    protected User $shop;
    protected array $fieldMappings = [];
    protected string $currency = 'USD';
    protected string $dateFormat = 'Y-m-d H:i:s';
    protected string $timezone = 'UTC';

    /**
     * Initialize transformer with shop
     */
    public function __construct(User $shop)
    {
        $this->shop = $shop;
        $this->loadFieldMappings();
        $this->loadShopSettings();
    }

    /**
     * Load field mappings from database
     */
    protected function loadFieldMappings(): void
    {
        $mappings = SyncFieldMapping::where('shop_id', $this->shop->id)
            ->where('is_active', true)
            ->orderBy('display_order')
            ->get();

        foreach ($mappings as $mapping) {
            $this->fieldMappings[$mapping->shopify_field] = $mapping->sheet_column;
        }
    }

    /**
     * Load shop-specific settings
     */
    protected function loadShopSettings(): void
    {
        // Get currency from shop settings (you may need to fetch this from Shopify)
        // For now, using default
        $this->currency = 'USD';
        $this->timezone = 'UTC';
    }

    /**
     * Transform Shopify product data to sheet format
     */
    public function transformShopifyToSheet(array $shopifyData): array
    {
        $rows = [];

        foreach ($shopifyData as $item) {
            $row = [];
            
            // Process each field mapping
            foreach ($this->fieldMappings as $shopifyField => $sheetColumn) {
                $value = $this->extractFieldValue($item, $shopifyField);
                $row[$sheetColumn] = $this->formatValue($value, $shopifyField);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Transform sheet data to Shopify format
     */
    public function transformSheetToShopify(array $sheetData): array
    {
        $shopifyData = [];

        // Reverse mapping: sheet column -> shopify field
        $reverseMapping = array_flip($this->fieldMappings);

        foreach ($sheetData as $row) {
            $shopifyItem = [];

            foreach ($row as $column => $value) {
                $shopifyField = $reverseMapping[$column] ?? null;
                
                if ($shopifyField) {
                    $shopifyItem[$shopifyField] = $this->parseValue($value, $shopifyField);
                }
            }

            if (!empty($shopifyItem)) {
                $shopifyData[] = $shopifyItem;
            }
        }

        return $shopifyData;
    }

    /**
     * Extract field value from Shopify data structure
     */
    protected function extractFieldValue(array $item, string $field): mixed
    {
        // Handle nested fields with dot notation
        if (str_contains($field, '.')) {
            return data_get($item, $field);
        }

        // Handle special field cases
        return match ($field) {
            'product_title' => $item['title'] ?? '',
            'product_handle' => $item['handle'] ?? '',
            'product_description' => $item['description'] ?? '',
            'product_vendor' => $item['vendor'] ?? '',
            'product_type' => $item['productType'] ?? '',
            'product_tags' => is_array($item['tags'] ?? null) ? implode(', ', $item['tags']) : ($item['tags'] ?? ''),
            'variant_title' => $item['title'] ?? '',
            'variant_sku' => $item['sku'] ?? '',
            'variant_barcode' => $item['barcode'] ?? '',
            'variant_price' => $item['price'] ?? '0.00',
            'variant_compare_at_price' => $item['compareAtPrice'] ?? '',
            'variant_cost' => $item['cost'] ?? '',
            'variant_inventory_quantity' => $item['inventoryQuantity'] ?? 0,
            'variant_weight' => $item['weight'] ?? '',
            'variant_weight_unit' => $item['weightUnit'] ?? '',
            'variant_options' => $this->formatVariantOptions($item['selectedOptions'] ?? []),
            'product_created_at' => $item['createdAt'] ?? '',
            'product_updated_at' => $item['updatedAt'] ?? '',
            'product_published_at' => $item['publishedAt'] ?? '',
            default => $item[$field] ?? '',
        };
    }

    /**
     * Format variant options
     */
    protected function formatVariantOptions(array $options): string
    {
        if (empty($options)) {
            return '';
        }

        $formatted = [];
        foreach ($options as $option) {
            $name = $option['name'] ?? '';
            $value = $option['value'] ?? '';
            if ($name && $value) {
                $formatted[] = "{$name}: {$value}";
            }
        }

        return implode(' / ', $formatted);
    }

    /**
     * Format value for sheet display
     */
    protected function formatValue(mixed $value, string $field): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        // Handle different field types
        return match (true) {
            str_contains($field, 'price') || str_contains($field, 'cost') => $this->formatPrice($value),
            str_contains($field, 'inventory_quantity') => (string) (int) $value,
            str_contains($field, '_at') || str_contains($field, 'date') => $this->formatDate($value),
            str_contains($field, 'weight') && !str_contains($field, 'unit') => $this->formatWeight($value),
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_array($value) => implode(', ', $value),
            default => (string) $value,
        };
    }

    /**
     * Parse value from sheet format to Shopify format
     */
    protected function parseValue(mixed $value, string $field): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Handle different field types
        return match (true) {
            str_contains($field, 'price') || str_contains($field, 'cost') => $this->parsePrice($value),
            str_contains($field, 'inventory_quantity') => $this->parseInteger($value),
            str_contains($field, '_at') || str_contains($field, 'date') => $this->parseDate($value),
            str_contains($field, 'weight') && !str_contains($field, 'unit') => $this->parseFloat($value),
            str_contains($field, 'taxable') => $this->parseBoolean($value),
            default => (string) $value,
        };
    }

    /**
     * Format price with currency
     */
    protected function formatPrice(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $amount = is_numeric($value) ? (float) $value : 0;
        return number_format($amount, 2, '.', '') . ' ' . $this->currency;
    }

    /**
     * Parse price from sheet format
     */
    protected function parsePrice(mixed $value): string
    {
        if (is_numeric($value)) {
            return (string) $value;
        }

        // Remove currency symbols and spaces
        $cleaned = preg_replace('/[^\d.]/', '', (string) $value);
        return $cleaned ?: '0.00';
    }

    /**
     * Format date/time
     */
    protected function formatDate(mixed $value): string
    {
        if (empty($value)) {
            return '';
        }

        try {
            // Handle ISO 8601 format from Shopify
            if (is_string($value)) {
                $date = Carbon::parse($value);
            } elseif ($value instanceof Carbon) {
                $date = $value;
            } else {
                return (string) $value;
            }

            return $date->setTimezone($this->timezone)->format($this->dateFormat);
        } catch (Exception $e) {
            return (string) $value;
        }
    }

    /**
     * Parse date from sheet format
     */
    protected function parseDate(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            $date = Carbon::parse($value);
            return $date->toIso8601String();
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Format weight
     */
    protected function formatWeight(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return is_numeric($value) ? number_format((float) $value, 2) : (string) $value;
    }

    /**
     * Parse integer
     */
    protected function parseInteger(mixed $value): int
    {
        return (int) $value;
    }

    /**
     * Parse float
     */
    protected function parseFloat(mixed $value): float
    {
        return (float) $value;
    }

    /**
     * Parse boolean
     */
    protected function parseBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $stringValue = strtolower(trim((string) $value));
        return in_array($stringValue, ['true', '1', 'yes', 'y', 'on']);
    }

    /**
     * Validate data before import
     */
    public function validateImportData(array $data): array
    {
        $errors = [];
        $validated = [];

        foreach ($data as $index => $row) {
            $rowErrors = [];
            $rowNumber = $index + 1;

            // Validate required fields
            foreach ($this->fieldMappings as $shopifyField => $sheetColumn) {
                if ($this->isRequiredField($shopifyField)) {
                    $value = $row[$sheetColumn] ?? null;
                    if (empty($value)) {
                        $rowErrors[] = "Required field '{$shopifyField}' (column {$sheetColumn}) is missing";
                    }
                }
            }

            // Validate data types
            foreach ($row as $column => $value) {
                $shopifyField = array_search($column, $this->fieldMappings);
                if ($shopifyField) {
                    $validationError = $this->validateFieldValue($value, $shopifyField);
                    if ($validationError) {
                        $rowErrors[] = $validationError;
                    }
                }
            }

            if (empty($rowErrors)) {
                $validated[] = $row;
            } else {
                $errors[] = [
                    'row' => $rowNumber,
                    'errors' => $rowErrors,
                ];
            }
        }

        return [
            'valid' => $validated,
            'errors' => $errors,
            'total_rows' => count($data),
            'valid_rows' => count($validated),
            'error_rows' => count($errors),
        ];
    }

    /**
     * Validate data before export
     */
    public function validateExportData(array $data): array
    {
        $errors = [];

        foreach ($data as $index => $item) {
            $rowErrors = [];
            $rowNumber = $index + 1;

            // Validate Shopify data structure
            if (!isset($item['id']) && !isset($item['title'])) {
                $rowErrors[] = 'Missing required identifier (id or title)';
            }

            // Validate variant data if present
            if (isset($item['variants']) && is_array($item['variants'])) {
                foreach ($item['variants'] as $variantIndex => $variant) {
                    if (!isset($variant['id']) && !isset($variant['sku'])) {
                        $rowErrors[] = "Variant #{$variantIndex} missing identifier";
                    }
                }
            }

            if (!empty($rowErrors)) {
                $errors[] = [
                    'row' => $rowNumber,
                    'errors' => $rowErrors,
                ];
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'total_rows' => count($data),
            'error_rows' => count($errors),
        ];
    }

    /**
     * Check if field is required
     */
    protected function isRequiredField(string $field): bool
    {
        $requiredFields = [
            'product_title',
            'variant_sku',
            'variant_price',
        ];

        return in_array($field, $requiredFields);
    }

    /**
     * Validate field value
     */
    protected function validateFieldValue(mixed $value, string $field): ?string
    {
        if (str_contains($field, 'price') || str_contains($field, 'cost')) {
            $price = $this->parsePrice($value);
            if (!is_numeric($price) || $price < 0) {
                return "Invalid price value for field '{$field}': {$value}";
            }
        }

        if (str_contains($field, 'inventory_quantity')) {
            $quantity = $this->parseInteger($value);
            if ($quantity < 0) {
                return "Inventory quantity cannot be negative for field '{$field}': {$value}";
            }
        }

        if (str_contains($field, 'weight') && !str_contains($field, 'unit')) {
            $weight = $this->parseFloat($value);
            if ($weight < 0) {
                return "Weight cannot be negative for field '{$field}': {$value}";
            }
        }

        return null;
    }

    /**
     * Get field mappings
     */
    public function getFieldMappings(): array
    {
        return $this->fieldMappings;
    }

    /**
     * Set currency
     */
    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;
        return $this;
    }

    /**
     * Set date format
     */
    public function setDateFormat(string $format): self
    {
        $this->dateFormat = $format;
        return $this;
    }

    /**
     * Set timezone
     */
    public function setTimezone(string $timezone): self
    {
        $this->timezone = $timezone;
        return $this;
    }
}

