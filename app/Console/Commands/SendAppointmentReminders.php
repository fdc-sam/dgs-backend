<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentReminder;
use App\Services\NotificationService;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Sends reminders at each configured offset (setting reminder_offsets_minutes, default 24 h and 2 h).
 * Each (appointment, offset) is recorded once, so overlapping runs never double-send.
 * Offsets whose moment passed before the appointment was booked/rescheduled are skipped.
 */
class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:send-reminders';

    protected $description = 'Send upcoming appointment reminders';

    public function handle(SettingsService $settings, NotificationService $notifications): int
    {
        $offsets = $settings->reminderOffsets(); // descending, e.g. [1440, 120]
        if ($offsets === []) {
            return self::SUCCESS;
        }

        $now = now();
        $sent = 0;

        Appointment::query()
            ->whereIn('status', [AppointmentStatus::Pending->value, AppointmentStatus::Confirmed->value, AppointmentStatus::Rescheduled->value])
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->addMinutes(max($offsets)))
            ->with(['reminders', 'customer', 'branch', 'items'])
            ->chunkById(100, function ($appointments) use ($offsets, $now, $notifications, &$sent) {
                foreach ($appointments as $appointment) {
                    $recorded = $appointment->reminders->pluck('offset_minutes')->all();
                    $changedAt = $appointment->updated_at ?? $appointment->created_at;

                    // Offsets that are due now, not yet recorded, and whose moment came after the booking was (re)made.
                    $due = array_values(array_filter($offsets, fn (int $offset) => ! in_array($offset, $recorded, true)
                        && $appointment->starts_at->subMinutes($offset)->lte($now)
                        && $appointment->starts_at->subMinutes($offset)->gte($changedAt)));

                    if ($due === []) {
                        continue;
                    }

                    try {
                        // Own transaction/savepoint: on Postgres a failed insert would otherwise poison an outer transaction.
                        DB::transaction(function () use ($due, $appointment, $now) {
                            foreach ($due as $offset) {
                                AppointmentReminder::create(['appointment_id' => $appointment->id, 'offset_minutes' => $offset, 'sent_at' => $now]);
                            }
                        });
                    } catch (UniqueConstraintViolationException) {
                        continue; // another worker got it first
                    }

                    // If several are due at once (e.g. scheduler was down), send a single reminder.
                    $notifications->appointmentReminder($appointment);
                    $sent++;
                }
            });

        $this->info("Sent {$sent} reminder(s).");

        return self::SUCCESS;
    }
}
