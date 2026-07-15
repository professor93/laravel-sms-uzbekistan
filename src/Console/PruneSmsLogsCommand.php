<?php

declare(strict_types=1);

namespace Uzbek\Sms\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Uzbek\Sms\Models\SmsLog;

final class PruneSmsLogsCommand extends Command
{
    protected $signature = 'sms:prune {--days= : Delete rows older than this many days (falls back to sms.logging.prune_after_days)}';

    protected $description = 'Prune old rows from the sms_logs table';

    /**
     * Missing table or missing retention are states, not errors — the
     * command reports and exits 0 so schedules never break.
     */
    public function handle(): int
    {
        $table = (new SmsLog)->getTable();

        if (! Schema::hasTable($table)) {
            $this->warn(sprintf(
                'The [%s] table does not exist — nothing to prune. Publish and run the migrations to enable database logging.',
                $table,
            ));

            return self::SUCCESS;
        }

        $days = $this->option('days') ?? config('sms.logging.prune_after_days');

        if (! is_numeric($days) || (int) $days < 1) {
            $this->warn('No retention configured — pass --days or set sms.logging.prune_after_days.');

            return self::SUCCESS;
        }

        $deleted = SmsLog::query()->where('created_at', '<', now()->subDays((int) $days))->delete();

        $this->info(sprintf('Pruned %d rows older than %d days.', $deleted, (int) $days));

        return self::SUCCESS;
    }
}
