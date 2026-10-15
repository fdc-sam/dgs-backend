<?php

use Illuminate\Support\Facades\Schedule;

// Run `php artisan schedule:work` locally, or a cron entry `* * * * * php artisan schedule:run` in production.
Schedule::command('appointments:send-reminders')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('appointments:release-unpaid')->everyMinute()->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();
Schedule::command('model:prune')->daily();
// Keep the notifications table small: read notifications are removed 30 days after they were sent.
Schedule::command('notifications:prune-read --days=30')->dailyAt('03:00')->withoutOverlapping();
