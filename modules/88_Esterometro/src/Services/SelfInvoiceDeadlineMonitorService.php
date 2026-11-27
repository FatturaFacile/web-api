<?php

declare(strict_types=1);

namespace Modules\Esterometro\Services;

use Illuminate\Support\Carbon;
use Modules\Esterometro\Domain\Enums\DeadlineUrgency;
use Modules\Esterometro\Domain\Enums\SdiTransmissionStatus;
use Modules\Esterometro\Domain\Enums\SelfInvoiceDocumentType;
use Modules\Esterometro\Domain\Enums\SelfInvoiceStatus;
use Modules\Esterometro\Models\SelfInvoice;

/**
 * Statutory deadline engine (15th of following month for TD16-TD19/TD28; 4-month / 30-day rules for TD20).
 */
final class SelfInvoiceDeadlineMonitorService
{
    public function computeTransmissionDeadline(
        SelfInvoiceDocumentType $documentType,
        Carbon $integrationDate,
        ?Carbon $operationDate = null,
        ?Carbon $receiptDate = null,
        ?string $regularizationTrigger = null,
    ): Carbon {
        if ($documentType === SelfInvoiceDocumentType::TD20) {
            $base = $operationDate ?? $receiptDate ?? $integrationDate;
            if ($regularizationTrigger === 'irregular_30_days') {
                return $base->copy()->addDays(30)->startOfDay();
            }

            return $base->copy()->addMonthsNoOverflow(4)->startOfDay();
        }

        $taxPoint = $receiptDate ?? $operationDate ?? $integrationDate;

        return $taxPoint->copy()->addMonthNoOverflow()->day(15)->startOfDay();
    }

    public function computeUrgency(SelfInvoice $invoice, ?Carbon $asOf = null): DeadlineUrgency
    {
        if (in_array($invoice->sdi_status, [SdiTransmissionStatus::SentToSdi, SdiTransmissionStatus::DeliveredRc], true)) {
            return DeadlineUrgency::Transmitted;
        }

        $today = ($asOf ?? Carbon::today())->copy()->startOfDay();
        $deadline = $invoice->transmission_deadline_date->copy()->startOfDay();

        if ($deadline->lt($today)) {
            return DeadlineUrgency::Overdue;
        }

        if ($today->diffInDays($deadline, false) <= 5) {
            return DeadlineUrgency::DueSoon;
        }

        return DeadlineUrgency::Upcoming;
    }

    /**
     * Dashboard KPI summary and TD16-TD28 breakdown for the tenant.
     *
     * @return array<string, mixed>
     */
    public function getDashboardSummary(string $tenantId, ?Carbon $asOf = null): array
    {
        $today = ($asOf ?? Carbon::today())->copy()->startOfDay();

        $invoices = SelfInvoice::query()
            ->where('tenant_id', $tenantId)
            ->where('status', '!=', SelfInvoiceStatus::Cancelled->value)
            ->get();

        $counts = [
            'total' => $invoices->count(),
            'overdue' => 0,
            'due_soon' => 0,
            'upcoming' => 0,
            'transmitted' => 0,
            'rejected' => 0,
            'draft' => 0,
            'reconciled' => 0,
        ];

        $totalsEur = [
            'taxable_eur' => 0.0,
            'vat_eur' => 0.0,
            'deductible_vat_eur' => 0.0,
            'non_deductible_vat_eur' => 0.0,
        ];

        $byType = [];
        foreach (SelfInvoiceDocumentType::cases() as $type) {
            $byType[$type->value] = [
                'document_type' => $type->value,
                'label' => $type->description(),
                'count' => 0,
                'taxable_eur' => 0.0,
                'vat_eur' => 0.0,
                'overdue_count' => 0,
            ];
        }

        foreach ($invoices as $invoice) {
            $urgency = $this->computeUrgency($invoice, $today);
            match ($urgency) {
                DeadlineUrgency::Overdue => $counts['overdue']++,
                DeadlineUrgency::DueSoon => $counts['due_soon']++,
                DeadlineUrgency::Upcoming => $counts['upcoming']++,
                DeadlineUrgency::Transmitted => $counts['transmitted']++,
            };

            if ($invoice->sdi_status === SdiTransmissionStatus::RejectedNs) {
                $counts['rejected']++;
            }
            if ($invoice->status === SelfInvoiceStatus::Draft) {
                $counts['draft']++;
            }
            if ($invoice->is_vat_reconciled) {
                $counts['reconciled']++;
            }

            $taxable = (float) $invoice->taxable_amount_eur;
            $vat = (float) $invoice->vat_amount_eur;
            $totalsEur['taxable_eur'] += $taxable;
            $totalsEur['vat_eur'] += $vat;
            $totalsEur['deductible_vat_eur'] += (float) $invoice->deductible_vat_eur;
            $totalsEur['non_deductible_vat_eur'] += (float) $invoice->non_deductible_vat_eur;

            $typeKey = $invoice->document_type->value;
            if (isset($byType[$typeKey])) {
                $byType[$typeKey]['count']++;
                $byType[$typeKey]['taxable_eur'] += $taxable;
                $byType[$typeKey]['vat_eur'] += $vat;
                if ($urgency === DeadlineUrgency::Overdue) {
                    $byType[$typeKey]['overdue_count']++;
                }
            }
        }

        $nextDeadline = $today->day <= 15
            ? $today->copy()->day(15)
            : $today->copy()->addMonthNoOverflow()->day(15);

        return [
            'as_of' => $today->toDateString(),
            'next_monthly_deadline' => $nextDeadline->toDateString(),
            'days_to_next_monthly_deadline' => $today->diffInDays($nextDeadline, false),
            'counts' => $counts,
            'totals_eur' => array_map(static fn (float $v): float => round($v, 2), $totalsEur),
            'by_document_type' => array_values($byType),
        ];
    }

    /**
     * Return untransmitted self-invoices that are overdue or due within $daysAhead days.
     *
     * @return list<SelfInvoice>
     */
    public function findAlertCandidates(?string $tenantId = null, int $daysAhead = 5, ?Carbon $asOf = null): array
    {
        $today = ($asOf ?? Carbon::today())->copy()->startOfDay();
        $cutoff = $today->copy()->addDays($daysAhead)->toDateString();

        return SelfInvoice::query()
            ->when($tenantId !== null, static fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('status', '!=', SelfInvoiceStatus::Cancelled->value)
            ->whereNotIn('sdi_status', [SdiTransmissionStatus::SentToSdi->value, SdiTransmissionStatus::DeliveredRc->value])
            ->whereDate('transmission_deadline_date', '<=', $cutoff)
            ->orderBy('transmission_deadline_date')
            ->get()
            ->all();
    }
}
