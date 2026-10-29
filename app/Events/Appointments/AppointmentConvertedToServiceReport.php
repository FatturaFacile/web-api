<?php

declare(strict_types=1);

namespace App\Events\Appointments;

use App\Models\Appointment;
use App\Models\ServiceReport;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AppointmentConvertedToServiceReport
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Appointment $appointment,
        public readonly ServiceReport $serviceReport
    ) {}
}
