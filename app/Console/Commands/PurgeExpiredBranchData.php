<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\Branch\BranchService;
use Illuminate\Console\Command;

class PurgeExpiredBranchData extends Command
{
    protected $signature = 'retail360:purge-expired-branches';

    protected $description = 'Delete branch data after purge grace period';

    public function handle(BranchService $branches): int
    {
        $purged = 0;

        Store::query()
            ->where('is_default', false)
            ->whereNotNull('data_purge_scheduled_at')
            ->where('data_purge_scheduled_at', '<=', now())
            ->chunkById(50, function ($stores) use ($branches, &$purged) {
                foreach ($stores as $store) {
                    try {
                        $branches->purgeExpired($store);
                        $purged++;
                    } catch (\Throwable $e) {
                        $this->warn("Skipped branch {$store->id}: {$e->getMessage()}");
                    }
                }
            });

        $this->info("Purged {$purged} expired branches.");

        return self::SUCCESS;
    }
}
