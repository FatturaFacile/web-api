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

class NotifyTechniciansAssignedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly Appointment $appointment)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $appointment = $this->appointment->fresh(['attendees.user']);
        if (! $appointment || $appointment->isCancelled()) {
            return;
        }

        foreach ($appointment->attendees as $attendee) {
            $user = $attendee->user;
            if ($user && $user->email) {
                Log::info("Technician {$user->name} ({$user->email}) notified for appointment {$appointment->appointment_number} at {$appointment->starts_at}");
            }
        }
    }
}
