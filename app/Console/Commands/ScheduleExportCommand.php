<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\SyncManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ScheduleExportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:export-scheduled 
                            {--shop-id= : Specific shop ID to export}
                            {--strategy=incremental : Sync strategy (full, incremental, selective)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run scheduled export for all shops or a specific shop';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $shopId = $this->option('shop-id');
        $strategy = $this->option('strategy');

        if ($shopId) {
            // Export for specific shop
            $shop = User::find($shopId);
            if (!$shop) {
                $this->error("Shop with ID {$shopId} not found");
                return 1;
            }

            $this->exportForShop($shop, $strategy);
        } else {
            // Export for all shops with Google Sheets connected
            $shops = User::whereHas('googleSheetsConnection', function ($query) {
                $query->whereNotNull('sheet_id');
            })->get();

            if ($shops->isEmpty()) {
                $this->info('No shops with Google Sheets connections found');
                return 0;
            }

            $this->info("Found {$shops->count()} shops to export");

            foreach ($shops as $shop) {
                $this->exportForShop($shop, $strategy);
            }
        }

        return 0;
    }

    /**
     * Export for a specific shop
     */
    protected function exportForShop(User $shop, string $strategy): void
    {
        try {
            $this->info("Starting export for shop: {$shop->name} (ID: {$shop->id})");

            $syncManager = new SyncManager($shop);
            $result = $syncManager->startSync(
                $strategy,
                true, // async
                SyncManager::CONFLICT_RESOLUTION_SHOPIFY_WINS,
                []
            );

            $this->info("Export queued for shop {$shop->id}: Sync log ID {$result['sync_log_id']}");

            Log::info('Scheduled export started', [
                'shop_id' => $shop->id,
                'strategy' => $strategy,
                'sync_log_id' => $result['sync_log_id'] ?? null,
            ]);
        } catch (\Exception $e) {
            $this->error("Failed to export for shop {$shop->id}: {$e->getMessage()}");
            Log::error('Scheduled export failed', [
                'shop_id' => $shop->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
