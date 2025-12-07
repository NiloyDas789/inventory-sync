<?php

namespace App\Http\Controllers;

use App\Models\GoogleSheetsConnection;
use App\Models\User;
use App\Services\GoogleSheetsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;

class GoogleSheetsController extends Controller
{
    protected GoogleSheetsService $sheetsService;

    public function __construct(GoogleSheetsService $sheetsService)
    {
        $this->sheetsService = $sheetsService;
    }

    /**
     * Initiate OAuth flow - redirect to Google
     */
    public function connect(Request $request): RedirectResponse
    {
        $shop = $request->user();
        
        if (!$shop instanceof User) {
            return redirect()->back()->with('error', 'Shop not authenticated');
        }

        try {
            $state = encrypt([
                'shop_id' => $shop->id,
                'return_url' => $request->get('return_url', route('home')),
            ]);

            $authUrl = $this->sheetsService->getAuthUrl($state);
            
            return redirect($authUrl);
        } catch (Exception $e) {
            Log::error('Error initiating Google OAuth', [
                'shop_id' => $shop->id,
                'error' => $e->getMessage(),
            ]);
            
            return redirect()->back()->with('error', 'Failed to initiate Google authentication');
        }
    }

    /**
     * Handle OAuth callback from Google
     */
    public function callback(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string',
            'state' => 'required|string',
        ]);

        if ($validator->fails()) {
            return redirect()->route('home')->with('error', 'Invalid OAuth callback');
        }

        try {
            $state = decrypt($request->get('state'));
            $shopId = $state['shop_id'] ?? null;
            $returnUrl = $state['return_url'] ?? route('home');

            if (!$shopId) {
                throw new Exception('Invalid state parameter');
            }

            $shop = User::findOrFail($shopId);
            
            // Handle the OAuth callback
            $connection = $this->sheetsService->handleCallback($request->get('code'), $shop);

            return redirect($returnUrl)->with('success', 'Google Sheets connected successfully');
        } catch (Exception $e) {
            Log::error('Google OAuth callback error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->route('home')->with('error', 'Failed to connect Google Sheets: ' . $e->getMessage());
        }
    }

    /**
     * Disconnect Google Sheets connection
     */
    public function disconnect(Request $request): JsonResponse
    {
        $shop = $request->user();
        
        if (!$shop instanceof User) {
            return response()->json(['error' => 'Shop not authenticated'], 401);
        }

        try {
            $connection = GoogleSheetsConnection::where('shop_id', $shop->id)->first();
            
            if ($connection) {
                $connection->delete();
            }

            return response()->json([
                'success' => true,
                'message' => 'Google Sheets disconnected successfully',
            ]);
        } catch (Exception $e) {
            Log::error('Error disconnecting Google Sheets', [
                'shop_id' => $shop->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to disconnect Google Sheets',
            ], 500);
        }
    }

    /**
     * Test Google Sheets connection
     */
    public function test(Request $request): JsonResponse
    {
        $shop = $request->user();
        
        if (!$shop instanceof User) {
            return response()->json(['error' => 'Shop not authenticated'], 401);
        }

        $validator = Validator::make($request->all(), [
            'sheet_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $this->sheetsService->setConnection($shop);
            
            $sheetId = $request->get('sheet_id');
            
            // Validate access
            $hasAccess = $this->sheetsService->validateSheetAccess($sheetId);
            
            if (!$hasAccess) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot access the specified Google Sheet. Please check permissions.',
                ], 403);
            }

            // Get spreadsheet info
            $info = $this->sheetsService->getSpreadsheetInfo($sheetId);
            
            // Update connection with sheet info
            $connection = GoogleSheetsConnection::where('shop_id', $shop->id)->first();
            if ($connection) {
                $connection->update([
                    'sheet_id' => $sheetId,
                    'sheet_url' => "https://docs.google.com/spreadsheets/d/{$sheetId}",
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Connection test successful',
                'data' => [
                    'spreadsheet_id' => $info['id'],
                    'title' => $info['title'],
                    'sheets' => $info['sheets'],
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Error testing Google Sheets connection', [
                'shop_id' => $shop->id,
                'sheet_id' => $request->get('sheet_id'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Connection test failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get connection status
     */
    public function status(Request $request): JsonResponse
    {
        $shop = $request->user();
        
        if (!$shop instanceof User) {
            return response()->json(['error' => 'Shop not authenticated'], 401);
        }

        try {
            $connection = GoogleSheetsConnection::where('shop_id', $shop->id)->first();
            
            if (!$connection || !$connection->hasValidTokens()) {
                return response()->json([
                    'connected' => false,
                    'message' => 'No active Google Sheets connection',
                ]);
            }

            $info = null;
            if ($connection->sheet_id) {
                try {
                    $this->sheetsService->setConnection($shop);
                    $info = $this->sheetsService->getSpreadsheetInfo($connection->sheet_id);
                } catch (Exception $e) {
                    Log::warning('Error getting spreadsheet info for status', [
                        'connection_id' => $connection->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'connected' => true,
                'sheet_id' => $connection->sheet_id,
                'sheet_url' => $connection->sheet_url,
                'last_synced_at' => $connection->last_synced_at?->toIso8601String(),
                'spreadsheet_info' => $info,
            ]);
        } catch (Exception $e) {
            Log::error('Error getting connection status', [
                'shop_id' => $shop->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to get connection status',
            ], 500);
        }
    }

    /**
     * Validate sheet structure
     */
    public function validateStructure(Request $request): JsonResponse
    {
        $shop = $request->user();
        
        if (!$shop instanceof User) {
            return response()->json(['error' => 'Shop not authenticated'], 401);
        }

        $validator = Validator::make($request->all(), [
            'sheet_id' => 'required|string',
            'sheet_name' => 'required|string',
            'required_columns' => 'required|array',
            'required_columns.*' => 'string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $this->sheetsService->setConnection($shop);
            
            $result = $this->sheetsService->validateSheetStructure(
                $request->get('sheet_id'),
                $request->get('sheet_name'),
                $request->get('required_columns')
            );

            return response()->json($result);
        } catch (Exception $e) {
            Log::error('Error validating sheet structure', [
                'shop_id' => $shop->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to validate sheet structure: ' . $e->getMessage(),
            ], 500);
        }
    }
}
