<?php

namespace App\Console\Commands;

use App\Models\Store;
use Illuminate\Console\Command;

class SyncBranchSubscriptionStatus extends Command
{
    protected $signature = 'retail360:sync-branch-subscriptions';

    protected $description = 'Sync branch subscription statuses (trial/active to expired)';

    public function handle(): int
    {
        Store::query()->chunkById(100, function ($stores) {
            foreach ($stores as $store) {
                $store->syncSubscriptionStatus();
            }
        });

        $this->info('Branch subscription statuses synced.');

        return self::SUCCESS;
    }
}
