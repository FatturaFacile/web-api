<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Application\Queries;

use Illuminate\Support\Carbon;
use Modules\BondedWarehouses\Domain\Enums\AeoStatus;
use Modules\BondedWarehouses\Domain\Enums\CustomsStatus;
use Modules\BondedWarehouses\Domain\Enums\DiscrepancyStatus;
use Modules\BondedWarehouses\Domain\Enums\RegimeType;
use Modules\BondedWarehouses\Models\AeoComplianceAudit;
use Modules\BondedWarehouses\Models\BondedMrnLot;
use Modules\BondedWarehouses\Models\CustomsWarehouseAuthorization;

/**
 * KPI read models: the CGU plafond monitor, the overview dashboard and the AEO compliance dashboard.
 */
final class DashboardQueries
{
    private const int EXPIRY_WINDOW_DAYS = 60;

    /**
     * Real-time plafond of every active authorization with a guarantee.
     *
     * @return list<array<string, mixed>>
     */
    public function plafond(): array
    {
        return CustomsWarehouseAuthorization::query()
            ->where('regime_type', '!=', RegimeType::VatDepotArt50Bis->value)
            ->orderBy('authorization_code')
            ->get()
            ->map(static fn (CustomsWarehouseAuthorization $a): array => [
                'authorization_id' => (string) $a->id,
                'authorization_code' => $a->authorization_code,
                'regime_type' => $a->regime_type->value,
                'currency' => $a->currency,
                'cgu_guarantee_reference' => $a->cgu_guarantee_reference,
                'ceiling' => $a->cgu_max_ceiling_amount,
                'utilized' => $a->cgu_utilized_amount,
                'available' => $a->availablePlafond(),
                'utilization_percent' => $a->utilizationPercent(),
                'is_active' => $a->is_active,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $authorizations = CustomsWarehouseAuthorization::query()->get();
        $customs = $authorizations->filter(static fn (CustomsWarehouseAuthorization $a): bool => $a->regime_type !== RegimeType::VatDepotArt50Bis);
        $today = Carbon::today();
        $window = $today->copy()->addDays(self::EXPIRY_WINDOW_DAYS);

        $byCurrency = [];

        foreach ($customs->where('is_active', true) as $authorization) {
            $row = $byCurrency[$authorization->currency] ?? ['currency' => $authorization->currency, 'ceiling' => 0.0, 'utilized' => 0.0, 'available' => 0.0];
            $row['ceiling'] += (float) $authorization->cgu_max_ceiling_amount;
            $row['utilized'] += (float) $authorization->cgu_utilized_amount;
            $row['available'] += (float) $authorization->availablePlafond();
            $byCurrency[$authorization->currency] = $row;
        }

        $expiring = $authorizations
            ->filter(static fn (CustomsWarehouseAuthorization $a): bool => $a->is_active && $a->valid_to !== null && $a->valid_to->betweenIncluded($today, $window))
            ->map(static fn (CustomsWarehouseAuthorization $a): array => ['authorization_id' => (string) $a->id, 'authorization_code' => $a->authorization_code, 'valid_to' => $a->valid_to?->toDateString()])
            ->values()
            ->all();

        return [
            'authorizations' => ['total' => $authorizations->count(), 'active' => $authorizations->where('is_active', true)->count()],
            'cgu' => array_values(array_map(static fn (array $row): array => [
                'currency' => $row['currency'],
                'ceiling' => number_format($row['ceiling'], 2, '.', ''),
                'utilized' => number_format($row['utilized'], 2, '.', ''),
                'available' => number_format($row['available'], 2, '.', ''),
                'utilization_percent' => $row['ceiling'] > 0 ? round($row['utilized'] / $row['ceiling'] * 100, 2) : 0.0,
            ], $byCurrency)),
            'expiring_authorizations' => $expiring,
            'open_lots' => BondedMrnLot::query()->where('remaining_quantity', '>', 0)->count(),
            'usual_handling_in_progress' => BondedMrnLot::query()->where('customs_status', CustomsStatus::UsualHandlingInProgress->value)->count(),
            'aeo_compliance_score' => $this->aeoScore(),
            'plafond' => $this->plafond(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function aeoDashboard(): array
    {
        $since = Carbon::today()->subDays(90)->toDateString();
        $audits = AeoComplianceAudit::query()->whereDate('audit_date', '>=', $since)->get(['discrepancy_status', 'audit_type']);
        $authorizations = CustomsWarehouseAuthorization::query()->where('aeo_status', '!=', AeoStatus::None->value)->get();

        return [
            'score' => $this->aeoScore(),
            'audits_last_90_days' => $audits->count(),
            'matched' => $audits->where('discrepancy_status', DiscrepancyStatus::Matched)->count(),
            'minor' => $audits->where('discrepancy_status', DiscrepancyStatus::MinorVariance)->count(),
            'critical' => $audits->where('discrepancy_status', DiscrepancyStatus::CriticalAlertADMUDSC)->count(),
            'certified_authorizations' => $authorizations->map(static fn (CustomsWarehouseAuthorization $a): array => [
                'authorization_id' => (string) $a->id,
                'authorization_code' => $a->authorization_code,
                'aeo_status' => $a->aeo_status->value,
                'aeo_certificate_number' => $a->aeo_certificate_number,
                'aeo_next_audit_date' => $a->aeo_next_audit_date?->toDateString(),
                'audit_overdue' => $a->aeo_next_audit_date !== null && $a->aeo_next_audit_date->lt(Carbon::today()),
            ])->values()->all(),
        ];
    }

    /**
     * Share of the audits of the last 90 days without a critical finding, weighted: matched 1, minor 0.5, critical 0. 100 when nothing was audited yet.
     */
    private function aeoScore(): float
    {
        $audits = AeoComplianceAudit::query()->whereDate('audit_date', '>=', Carbon::today()->subDays(90)->toDateString())->get(['discrepancy_status']);

        if ($audits->isEmpty()) {
            return 100.0;
        }

        $points = $audits->sum(static fn (AeoComplianceAudit $audit): float => match ($audit->discrepancy_status) {
            DiscrepancyStatus::Matched => 1.0,
            DiscrepancyStatus::MinorVariance => 0.5,
            DiscrepancyStatus::CriticalAlertADMUDSC => 0.0,
        });

        return round($points / $audits->count() * 100, 1);
    }
}
