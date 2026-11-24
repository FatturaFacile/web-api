<?php

declare(strict_types=1);

namespace App\Listeners\ServiceReports;

use App\Events\ServiceReports\ServiceReportCreated;
use App\Events\ServiceReports\ServiceReportInvoiced;
use App\Events\ServiceReports\ServiceReportSigned;
use App\Models\ServiceReport;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;

class LogServiceReportActivity
{
    public function __construct(
        private readonly AuditLedgerInterface $auditLedger
    ) {}

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
        $this->auditLedger->record(
            action: strtoupper("service_report_{$event}"),
            model: $report,
            payloadBefore: null,
            payloadAfter: $newValues,
            moduleCode: 'SERVICE'
        );
    }
}
