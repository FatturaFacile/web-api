<?php

declare(strict_types=1);

namespace Modules\Esterometro\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Esterometro\Domain\Enums\DeadlineUrgency;
use Modules\Esterometro\Services\SelfInvoiceDeadlineMonitorService;

/**
 * Evaluates statutory 15th-of-following-month deadlines for TD16–TD28 self-invoices and emits compliance alerts.
 */
final class CheckEsterometroDeadlinesCommand extends Command
{
    protected $signature = 'esterometro:check-deadlines {--tenant= : Optional tenant UUID} {--days=5 : Alert window in days}';

    protected $description = 'Check Italian Esterometro (TD16-TD28) transmission deadlines and flag overdue/due-soon documents.';

    public function handle(SelfInvoiceDeadlineMonitorService $monitor): int
    {
        $tenantOption = $this->option('tenant');
        $tenantId = is_string($tenantOption) && $tenantOption !== '' ? $tenantOption : null;
        $daysAhead = max(1, (int) $this->option('days'));
        $today = Carbon::today();

        $candidates = $monitor->findAlertCandidates($tenantId, $daysAhead, $today);

        $overdueCount = 0;
        $dueSoonCount = 0;

        foreach ($candidates as $invoice) {
            $urgency = $monitor->computeUrgency($invoice, $today);
            if ($urgency === DeadlineUrgency::Overdue) {
                $overdueCount++;
            } elseif ($urgency === DeadlineUrgency::DueSoon) {
                $dueSoonCount++;
            }

            Log::warning('esterometro.deadline_alert', [
                'tenant_id' => $invoice->tenant_id,
                'self_invoice_id' => $invoice->id,
                'document_number' => $invoice->document_number,
                'document_type' => $invoice->document_type->value,
                'deadline' => $invoice->transmission_deadline_date->toDateString(),
                'urgency' => $urgency->value,
            ]);
        }

        $this->info("Esterometro deadlines checked: {$overdueCount} overdue, {$dueSoonCount} due within {$daysAhead} days.");

        return self::SUCCESS;
    }
}
