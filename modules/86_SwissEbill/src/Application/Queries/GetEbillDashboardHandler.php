<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Queries;

use Illuminate\Support\Facades\DB;
use Modules\SwissEbill\Domain\Enums\EbillTransmissionStatus;
use Modules\SwissEbill\Models\EbillTransmission;

/**
 * KPIs of the eBill monitoring dashboard: counts and amounts per currency of the transmissions in each stage.
 */
final class GetEbillDashboardHandler
{
    /**
     * @return array<string, array{count: int, amounts: array<string, float>}>
     */
    public function handle(?string $from = null, ?string $to = null): array
    {
        $rows = EbillTransmission::query()
            ->when($from, fn ($query, $value) => $query->where('created_at', '>=', $value))
            ->when($to, fn ($query, $value) => $query->where('created_at', '<=', $value.' 23:59:59'))
            ->select('status', 'currency', DB::raw('COUNT(*) as total'), DB::raw('SUM(invoice_amount) as amount'))
            ->groupBy('status', 'currency')
            ->get();

        $stages = [
            // Everything that reached the network, whatever happened next.
            'total_transmitted' => [EbillTransmissionStatus::Transmitted, EbillTransmissionStatus::DeliveredToEbanking, EbillTransmissionStatus::ApprovedByDebtor, EbillTransmissionStatus::RejectedByDebtor, EbillTransmissionStatus::PaidReconciled],
            'awaiting_approval' => [EbillTransmissionStatus::Transmitted, EbillTransmissionStatus::DeliveredToEbanking],
            'approved' => [EbillTransmissionStatus::ApprovedByDebtor],
            'rejected' => [EbillTransmissionStatus::RejectedByDebtor],
            'reconciled' => [EbillTransmissionStatus::PaidReconciled],
            'failed' => [EbillTransmissionStatus::FailedTechnical],
        ];

        $kpis = [];

        foreach ($stages as $key => $statuses) {
            $values = array_map(static fn (EbillTransmissionStatus $s): string => $s->value, $statuses);
            $matching = $rows->filter(fn ($row): bool => in_array((string) $row->getRawOriginal('status'), $values, true));
            $amounts = [];

            foreach ($matching as $row) {
                $currency = (string) $row->currency;
                $amounts[$currency] = round(($amounts[$currency] ?? 0.0) + (float) $row->getAttribute('amount'), 2);
            }

            $kpis[$key] = ['count' => (int) $matching->sum(fn ($row): int => (int) $row->getAttribute('total')), 'amounts' => $amounts];
        }

        return $kpis;
    }
}
