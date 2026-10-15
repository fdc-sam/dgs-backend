<?php

namespace App\Console\Commands;

use App\Services\PaymentService;
use Illuminate\Console\Command;

/**
 * Keeps GCash deposit holds moving: reminds customers shortly before their payment deadline, then cancels
 * bookings whose deadline passed without a receipt so the slot is free again. Safe to run concurrently.
 */
class ReleaseUnpaidAppointments extends Command
{
    protected $signature = 'appointments:release-unpaid';

    protected $description = 'Remind customers about unpaid deposits and release slots whose payment window ended';

    public function handle(PaymentService $payments): int
    {
        $reminded = $payments->remindDueSoon();
        $released = $payments->expireOverdue();

        $this->info("Sent {$reminded} payment reminder(s), released {$released} unpaid booking(s).");

        return self::SUCCESS;
    }
}
