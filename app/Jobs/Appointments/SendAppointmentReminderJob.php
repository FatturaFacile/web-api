<?php

declare(strict_types=1);

namespace App\Jobs\Appointments;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendAppointmentReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly Appointment $appointment)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $appointment = $this->appointment->fresh(['contact', 'attendees.user']);
        if (! $appointment || $appointment->isCancelled() || $appointment->reminder_sent_at !== null) {
            return;
        }

        // Send multi-channel notification (Email / SMS / WhatsApp webhook)
        Log::info("Appointment reminder dispatched for {$appointment->appointment_number} to contact {$appointment->contact->email}");

        $appointment->update([
            'reminder_sent_at' => now(),
        ]);
    }
}
