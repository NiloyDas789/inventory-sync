<?php

namespace App\Services;

use App\Models\GoogleSheetsConnection;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Exception;

class GoogleSheetsService
{
    protected ?GoogleSheetsConnection $connection = null;
    protected ?string $accessToken = null;

    /**
     * Google OAuth endpoints
     */
    protected const OAUTH_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    protected const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * Google Sheets API base URL
     */
    protected const API_BASE_URL = 'https://sheets.googleapis.com/v4/spreadsheets';

    /**
     * Required OAuth scopes for Google Sheets API
     */
    protected const SCOPES = [
        'https://www.googleapis.com/auth/spreadsheets',
        'https://www.googleapis.com/auth/drive.readonly',
    ];

    /**
     * Initialize the service with a shop connection
     */
    public function __construct(?User $shop = null)
    {
        if ($shop) {
            $this->setConnection($shop);
        }
    }

    /**
     * Set the connection for a specific shop
     */
    public function setConnection(User $shop): self
    {
        $this->connection = GoogleSheetsConnection::where('shop_id', $shop->id)->first();

        if ($this->connection && $this->connection->hasValidTokens()) {
            $this->authenticateWithTokens();
        }

        return $this;
    }

    /**
     * Get OAuth authorization URL
     */
    public function getAuthUrl(?string $state = null): string
    {
        $clientId = config('services.google.client_id');
        $redirectUri = config('services.google.redirect_uri', url('/google-sheets/callback'));

        $params = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
        ];

        if ($state) {
            $params['state'] = $state;
        }

        return self::OAUTH_AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Handle OAuth callback and store tokens
     */
    public function handleCallback(string $code, User $shop): GoogleSheetsConnection
    {
        try {
            $clientId = config('services.google.client_id');
            $clientSecret = config('services.google.client_secret');
            $redirectUri = config('services.google.redirect_uri', url('/google-sheets/callback'));

            // Exchange authorization code for tokens
            $response = Http::asForm()->post(self::OAUTH_TOKEN_URL, [
                'code' => $code,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ]);

            if (!$response->successful()) {
                $error = $response->json();
                throw new Exception('OAuth error: ' . ($error['error_description'] ?? $error['error'] ?? 'Unknown error'));
            }

            $tokenData = $response->json();

            $accessToken = $tokenData['access_token'] ?? null;
            $refreshToken = $tokenData['refresh_token'] ?? null;

            if (!$accessToken) {
                throw new Exception('No access token received');
            }

            // Find or create connection
            $connection = GoogleSheetsConnection::firstOrNew(['shop_id' => $shop->id]);

            if ($accessToken) {
                $connection->setAccessToken($accessToken);
            }
            if ($refreshToken) {
                $connection->setRefreshToken($refreshToken);
            }

            $connection->save();

            // Set tokens for immediate use
            $this->accessToken = $accessToken;
            $this->connection = $connection;

            return $connection;
        } catch (Exception $e) {
            Log::error('Google Sheets OAuth callback error', [
                'shop_id' => $shop->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Authenticate using stored tokens
     */
    protected function authenticateWithTokens(): void
    {
        if (!$this->connection) {
            throw new Exception('No connection set');
        }

        try {
            $accessToken = $this->connection->getDecryptedAccessToken();
            $refreshToken = $this->connection->getDecryptedRefreshToken();

            if (!$refreshToken) {
                throw new Exception('No refresh token available');
            }

            $this->accessToken = $accessToken;

            // Check if token is expired (we'll refresh on first API call if needed)
        } catch (Exception $e) {
            Log::error('Google Sheets authentication error', [
                'connection_id' => $this->connection->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Refresh access token using refresh token
     */
    protected function refreshAccessToken(): void
    {
        if (!$this->connection) {
            throw new Exception('No connection set');
        }

        try {
            $refreshToken = $this->connection->getDecryptedRefreshToken();

            if (!$refreshToken) {
                throw new Exception('No refresh token available');
            }

            $clientId = config('services.google.client_id');
            $clientSecret = config('services.google.client_secret');

            $response = Http::asForm()->post(self::OAUTH_TOKEN_URL, [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]);

            if (!$response->successful()) {
                $error = $response->json();
                throw new Exception('Token refresh failed: ' . ($error['error_description'] ?? $error['error'] ?? 'Unknown error'));
            }

            $tokenData = $response->json();
            $newAccessToken = $tokenData['access_token'] ?? null;

            if ($newAccessToken) {
                // Update stored access token
                $this->connection->setAccessToken($newAccessToken);
                $this->connection->save();
                $this->accessToken = $newAccessToken;
            }

            // Update refresh token if a new one is provided
            if (isset($tokenData['refresh_token'])) {
                $this->connection->setRefreshToken($tokenData['refresh_token']);
                $this->connection->save();
            }
        } catch (Exception $e) {
            Log::error('Google Sheets token refresh error', [
                'connection_id' => $this->connection->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get valid access token, refreshing if necessary
     */
    protected function getAccessToken(): string
    {
        if (!$this->connection) {
            throw new Exception('No connection set. Call setConnection() first.');
        }

        if (!$this->accessToken) {
            $this->authenticateWithTokens();
        }

        // Try to refresh token if we suspect it's expired
        // In a real scenario, you'd check the token expiry time
        // For now, we'll refresh on 401 errors
        return $this->accessToken;
    }

    /**
     * Make authenticated API request
     */
    protected function makeRequest(string $method, string $url, ?array $data = null, array $queryParams = []): array
    {
        $accessToken = $this->getAccessToken();

        $request = Http::withToken($accessToken);

        if (!empty($queryParams)) {
            $request->withQueryParameters($queryParams);
        }

        if ($data) {
            $request->withBody(json_encode($data), 'application/json');
        }

        $response = $request->{strtolower($method)}($url);

        // Handle 401 - token expired, try refreshing
        if ($response->status() === 401) {
            $this->refreshAccessToken();
            $accessToken = $this->getAccessToken();

            $request = Http::withToken($accessToken);
            if (!empty($queryParams)) {
                $request->withQueryParameters($queryParams);
            }
            if ($data) {
                $request->withBody(json_encode($data), 'application/json');
            }
            $response = $request->{strtolower($method)}($url);
        }

        if (!$response->successful()) {
            $this->handleApiError($response);
        }

        return $response->json();
    }

    /**
     * Validate sheet access and permissions
     */
    public function validateSheetAccess(string $spreadsheetId): bool
    {
        try {
            $url = self::API_BASE_URL . '/' . $spreadsheetId;
            $this->makeRequest('GET', $url);
            return true;
        } catch (Exception $e) {
            Log::warning('Google Sheets access validation failed', [
                'spreadsheet_id' => $spreadsheetId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get spreadsheet metadata
     */
    public function getSpreadsheetInfo(string $spreadsheetId): array
    {
        try {
            $url = self::API_BASE_URL . '/' . $spreadsheetId;
            $data = $this->makeRequest('GET', $url);

            $sheets = [];
            if (isset($data['sheets'])) {
                foreach ($data['sheets'] as $sheet) {
                    $sheets[] = [
                        'id' => $sheet['properties']['sheetId'] ?? null,
                        'title' => $sheet['properties']['title'] ?? '',
                    ];
                }
            }

            return [
                'id' => $data['spreadsheetId'] ?? $spreadsheetId,
                'title' => $data['properties']['title'] ?? '',
                'sheets' => $sheets,
            ];
        } catch (Exception $e) {
            Log::error('Error getting spreadsheet info', [
                'spreadsheet_id' => $spreadsheetId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Read sheet data with pagination support
     */
    public function readSheet(
        string $spreadsheetId,
        string $range,
        ?string $sheetName = null,
        int $page = 1,
        int $pageSize = 1000
    ): array {
        try {
            $fullRange = $sheetName ? "{$sheetName}!{$range}" : $range;

            // For pagination, calculate offset
            if ($page > 1) {
                $offset = ($page - 1) * $pageSize;
                $fullRange = $this->adjustRangeForPagination($fullRange, $offset, $pageSize);
            } else {
                $fullRange = $this->limitRange($fullRange, $pageSize);
            }

            $url = self::API_BASE_URL . '/' . $spreadsheetId . '/values/' . urlencode($fullRange);
            $data = $this->makeRequest('GET', $url);

            $values = $data['values'] ?? [];

            return [
                'data' => $values,
                'page' => $page,
                'page_size' => $pageSize,
                'has_more' => count($values) >= $pageSize,
            ];
        } catch (Exception $e) {
            Log::error('Error reading sheet', [
                'spreadsheet_id' => $spreadsheetId,
                'range' => $range,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Write data to sheet (single range)
     */
    public function writeSheet(
        string $spreadsheetId,
        string $range,
        array $values,
        ?string $sheetName = null,
        string $valueInputOption = 'RAW'
    ): void {
        try {
            $fullRange = $sheetName ? "{$sheetName}!{$range}" : $range;

            $url = self::API_BASE_URL . '/' . $spreadsheetId . '/values/' . urlencode($fullRange);

            $data = [
                'values' => $values,
            ];

            $this->makeRequest('PUT', $url, $data, ['valueInputOption' => $valueInputOption]);
        } catch (Exception $e) {
            Log::error('Error writing to sheet', [
                'spreadsheet_id' => $spreadsheetId,
                'range' => $range,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Batch write to multiple ranges efficiently
     */
    public function batchWrite(
        string $spreadsheetId,
        array $data, // [['range' => 'A1:B10', 'values' => [...], 'sheet' => 'Sheet1'], ...]
        string $valueInputOption = 'RAW'
    ): void {
        try {
            $url = self::API_BASE_URL . '/' . $spreadsheetId . '/values:batchUpdate';

            $dataArray = [];
            foreach ($data as $item) {
                $range = $item['sheet']
                    ? "{$item['sheet']}!{$item['range']}"
                    : $item['range'];

                $dataArray[] = [
                    'range' => $range,
                    'values' => $item['values'],
                ];
            }

            $requestData = [
                'valueInputOption' => $valueInputOption,
                'data' => $dataArray,
            ];

            $this->makeRequest('POST', $url, $requestData);
        } catch (Exception $e) {
            Log::error('Error batch writing to sheet', [
                'spreadsheet_id' => $spreadsheetId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Validate sheet structure - check if required columns exist
     */
    public function validateSheetStructure(
        string $spreadsheetId,
        string $sheetName,
        array $requiredColumns
    ): array {
        try {
            // Read header row
            $headerRange = "{$sheetName}!1:1";
            $result = $this->readSheet($spreadsheetId, $headerRange, null, 1, 1);
            $headers = $result['data'][0] ?? [];

            $missingColumns = [];
            $columnMap = [];

            foreach ($requiredColumns as $requiredCol) {
                $found = false;
                foreach ($headers as $index => $header) {
                    if (strtolower(trim($header)) === strtolower(trim($requiredCol))) {
                        $columnMap[$requiredCol] = $this->numberToColumnLetter($index + 1);
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $missingColumns[] = $requiredCol;
                }
            }

            return [
                'valid' => empty($missingColumns),
                'missing_columns' => $missingColumns,
                'column_map' => $columnMap,
                'headers' => $headers,
            ];
        } catch (Exception $e) {
            Log::error('Error validating sheet structure', [
                'spreadsheet_id' => $spreadsheetId,
                'sheet_name' => $sheetName,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get column mapping based on user preferences
     */
    public function getColumnMapping(User $shop, ?string $sheetName = null): array
    {
        $mappings = $shop->syncFieldMappings()
            ->where('is_active', true)
            ->orderBy('display_order')
            ->get();

        $columnMap = [];
        foreach ($mappings as $mapping) {
            $columnMap[$mapping->shopify_field] = $mapping->sheet_column;
        }

        return $columnMap;
    }

    /**
     * Convert Shopify data to Google Sheets format
     */
    public function convertShopifyData(array $shopifyData, array $columnMapping): array
    {
        $rows = [];

        foreach ($shopifyData as $item) {
            $row = [];
            foreach ($columnMapping as $shopifyField => $sheetColumn) {
                $value = data_get($item, $shopifyField, '');
                $row[] = $this->convertDataType($value);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Convert data type for Google Sheets
     */
    protected function convertDataType($value)
    {
        if (is_null($value)) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        if (is_numeric($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            // Date format - keep as string or convert to Google Sheets date
            return $value;
        }

        return (string) $value;
    }

    /**
     * Get all sheets in a spreadsheet
     */
    public function getSheets(string $spreadsheetId): array
    {
        try {
            $info = $this->getSpreadsheetInfo($spreadsheetId);
            return $info['sheets'] ?? [];
        } catch (Exception $e) {
            Log::error('Error getting sheets', [
                'spreadsheet_id' => $spreadsheetId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Handle API errors
     */
    protected function handleApiError($response): void
    {
        $status = $response->status();
        $error = $response->json();
        $message = $error['error']['message'] ?? $error['error_description'] ?? 'Unknown error';

        // Rate limiting
        if ($status === 429 || str_contains($message, 'rateLimitExceeded')) {
            Log::warning('Google Sheets API rate limit exceeded', [
                'connection_id' => $this->connection?->id,
            ]);

            Cache::put('google_sheets_rate_limit', true, 60);

            throw new Exception('Google Sheets API rate limit exceeded. Please try again later.');
        }

        // Quota exceeded
        if ($status === 403 && str_contains($message, 'quotaExceeded')) {
            Log::error('Google Sheets API quota exceeded', [
                'connection_id' => $this->connection?->id,
            ]);
            throw new Exception('Google Sheets API quota exceeded.');
        }

        // Authentication errors
        if ($status === 401 || $status === 403) {
            Log::warning('Google Sheets API authentication error', [
                'connection_id' => $this->connection?->id,
                'status' => $status,
            ]);
            throw new Exception('Google Sheets authentication failed. Please reconnect your account.');
        }

        // Re-throw with error message
        throw new Exception('Google Sheets API error: ' . $message);
    }

    /**
     * Check if rate limited
     */
    public static function isRateLimited(): bool
    {
        return Cache::has('google_sheets_rate_limit');
    }

    /**
     * Adjust range for pagination
     */
    protected function adjustRangeForPagination(string $range, int $offset, int $limit): string
    {
        // Simple implementation - for complex ranges, you'd need to parse A1 notation
        if (preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $range, $matches)) {
            $startCol = $matches[1];
            $startRow = (int) $matches[2] + $offset;
            $endCol = $matches[3];
            $endRow = $startRow + $limit - 1;
            return "{$startCol}{$startRow}:{$endCol}{$endRow}";
        }

        return $range;
    }

    /**
     * Limit range size
     */
    protected function limitRange(string $range, int $maxRows): string
    {
        if (preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $range, $matches)) {
            $startCol = $matches[1];
            $startRow = (int) $matches[2];
            $endCol = $matches[3];
            $endRow = min((int) $matches[4], $startRow + $maxRows - 1);
            return "{$startCol}{$startRow}:{$endCol}{$endRow}";
        }

        return $range;
    }

    /**
     * Convert column number to letter (1 = A, 2 = B, etc.)
     */
    protected function numberToColumnLetter(int $number): string
    {
        $letter = '';
        while ($number > 0) {
            $number--;
            $letter = chr(65 + ($number % 26)) . $letter;
            $number = intval($number / 26);
        }
        return $letter;
    }
}
