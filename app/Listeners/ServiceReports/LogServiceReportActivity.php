<?php

declare(strict_types=1);

namespace App\Listeners\ServiceReports;

use App\Events\ServiceReports\ServiceReportCreated;
use App\Events\ServiceReports\ServiceReportInvoiced;
use App\Events\ServiceReports\ServiceReportSigned;
use App\Models\AuditLog;
use App\Models\ServiceReport;
use Illuminate\Support\Facades\Auth;

class LogServiceReportActivity
{
    public function handleServiceReportCreated(ServiceReportCreated $event): void
    {
        $this->recordAudit($event->serviceReport, 'created', [
            'report_number' => $event->serviceReport->report_number,
            'total_gross' => $event->serviceReport->total_gross,
        ]);
    }

    public function handleServiceReportSigned(ServiceReportSigned $event): void
    {
        $this->recordAudit($event->serviceReport, 'signed', [
            'signer_name' => $event->signature->signer_name,
            'signed_at' => $event->signature->signed_at?->toISOString(),
            'signature_hash' => $event->signature->signature_hash,
        ]);
    }

    public function handleServiceReportInvoiced(ServiceReportInvoiced $event): void
    {
        foreach ($event->serviceReports as $report) {
            $this->recordAudit($report, 'invoiced', [
                'invoice_id' => $event->invoice->id,
                'invoice_number' => $event->invoice->invoice_number,
                'is_consolidated' => $event->isConsolidated,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $newValues
     */
    private function recordAudit(ServiceReport $report, string $event, array $newValues): void
    {
        AuditLog::create([
            'tenant_id' => $report->tenant_id,
            'user_id' => Auth::id(),
            'event' => "service_report.{$event}",
            'auditable_type' => ServiceReport::class,
            'auditable_id' => $report->id,
            'new_values' => $newValues,
            'ip_address' => request()->ip() ?? '127.0.0.1',
            'user_agent' => request()->userAgent(),
        ]);
    }
}
