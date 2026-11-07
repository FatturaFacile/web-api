<?php

declare(strict_types=1);

namespace App\Jobs\Appointments;

use App\Mail\AppointmentReminderMail;
use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Mails the reminder of an appointment to the customer contact and to the assigned technicians, once: the reminder is
 * claimed by setting reminder_sent_at before sending, so a second run (or a concurrent one) sends nothing.
 */
class SendAppointmentReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly Appointment $appointment)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $appointment = $this->appointment->fresh(['contact', 'technicians']);
        if (! $appointment || $appointment->isCancelled() || $appointment->reminder_sent_at !== null) {
            return;
        }

        $claimed = Appointment::withoutGlobalScopes()
            ->whereKey($appointment->getKey())
            ->whereNull('reminder_sent_at')
            ->update(['reminder_sent_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $recipients = collect([$appointment->contact?->email])
            ->merge($appointment->technicians->pluck('email'))
            ->filter(static fn (?string $email): bool => filled($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values();

        foreach ($recipients as $email) {
            Mail::to($email)->send(new AppointmentReminderMail($appointment));
        }

        Log::info("Appointment reminder dispatched for {$appointment->appointment_number} to {$recipients->count()} recipient(s)");
    }
}
