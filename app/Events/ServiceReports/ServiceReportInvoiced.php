<?php

declare(strict_types=1);

namespace App\Events\ServiceReports;

use App\Models\Invoice;
use App\Models\ServiceReport;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServiceReportInvoiced
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, ServiceReport>  $serviceReports
     */
    public function __construct(
        public readonly Invoice $invoice,
        public readonly array $serviceReports,
        public readonly bool $isConsolidated = false
    ) {}
}
