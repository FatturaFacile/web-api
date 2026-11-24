<?php

declare(strict_types=1);

namespace App\Events\ServiceReports;

use App\Models\ServiceReport;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServiceReportCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly ServiceReport $serviceReport) {}
}
