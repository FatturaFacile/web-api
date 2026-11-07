<?php

declare(strict_types=1);

namespace App\Jobs\Appointments;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queues the reminder of every appointment of the tenant that starts within the configured lead time
 * (appointments.reminder_lead_minutes) and has no reminder yet. SendAppointmentReminderJob is idempotent, so running
 * this every few minutes never sends a reminder twice.
 */
final class QueueAppointmentRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $tenantId) {}

    public function handle(): void
    {
        $leadMinutes = max(1, (int) config('appointments.reminder_lead_minutes', 1440));

        Appointment::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->whereNull('reminder_sent_at')
            ->whereNull('cancelled_at')
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->addMinutes($leadMinutes))
            ->orderBy('starts_at')
            ->each(static function (Appointment $appointment): void {
                dispatch(new SendAppointmentReminderJob($appointment));
            });
    }
}
