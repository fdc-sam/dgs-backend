<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deletes notifications that have been READ and are older than N days (default 30), for customers and
 * staff alike. Unread notifications are never deleted. Runs daily from the scheduler (routes/console.php).
 * Deletes in small batches so the table is never locked for long.
 */
class PruneReadNotifications extends Command
{
    protected $signature = 'notifications:prune-read {--days=30 : Delete read notifications created more than this many days ago} {--dry-run}';

    protected $description = 'Delete read notifications older than the given number of days';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $query = DB::table('notifications')->whereNotNull('read_at')->where('created_at', '<', $cutoff);

        if ($this->option('dry-run')) {
            $this->info("Would delete {$query->count()} read notification(s) created before {$cutoff->toDateTimeString()}.");

            return self::SUCCESS;
        }

        $deleted = 0;
        do {
            $ids = (clone $query)->orderBy('created_at')->limit(1000)->pluck('id');
            $deleted += $ids->isEmpty() ? 0 : DB::table('notifications')->whereIn('id', $ids)->delete();
        } while ($ids->count() === 1000);

        $this->info("Deleted {$deleted} read notification(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
