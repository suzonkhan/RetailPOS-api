<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-off for legacy DBs that wrote wall-clock timestamps under UTC.
 * Fresh installs use APP_TIMEZONE=Asia/Dhaka and must not run this.
 */
class ConvertUtcTimestampsToDhakaCommand extends Command
{
    protected $signature = 'retail360:convert-utc-timestamps-to-dhaka
                            {--force : Skip confirmation}
                            {--reverse : Subtract 6 hours instead of add}';

    protected $description = 'Shift MySQL timestamp/datetime columns ±6h (legacy UTC → Asia/Dhaka). Do not re-run.';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->error('This command only supports MySQL.');

            return self::FAILURE;
        }

        $reverse = (bool) $this->option('reverse');
        $action = $reverse ? 'subtract' : 'add';

        if (! $this->option('force') && ! $this->confirm(
            "This will {$action} 6 hours on every timestamp/datetime column. Only for legacy UTC data. Continue?"
        )) {
            return self::FAILURE;
        }

        $sqlFn = $reverse ? 'DATE_SUB' : 'DATE_ADD';
        $updated = 0;

        foreach (Schema::getTableListing() as $table) {
            if (in_array($table, ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'], true)) {
                continue;
            }

            foreach (Schema::getColumns($table) as $column) {
                $type = strtolower((string) ($column['type_name'] ?? ''));
                if (! in_array($type, ['timestamp', 'datetime'], true)) {
                    continue;
                }

                $name = $column['name'];
                DB::table($table)
                    ->whereNotNull($name)
                    ->update([
                        $name => DB::raw("{$sqlFn}(`{$name}`, INTERVAL 6 HOUR)"),
                    ]);
                $updated++;
                $this->line("  {$table}.{$name}");
            }
        }

        $this->info("Updated {$updated} timestamp columns ({$action} 6 hours).");

        return self::SUCCESS;
    }
}
