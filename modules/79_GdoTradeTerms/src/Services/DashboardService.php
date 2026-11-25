<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Services;

use Illuminate\Support\Carbon;
use Modules\GdoTradeTerms\Domain\Enums\ContractStatus;
use Modules\GdoTradeTerms\Domain\Enums\LineStatus;
use Modules\GdoTradeTerms\Domain\Enums\PromotionStatus;
use Modules\GdoTradeTerms\Domain\Enums\RunStatus;
use Modules\GdoTradeTerms\Domain\Enums\SettlementStatus;
use Modules\GdoTradeTerms\Domain\Enums\SourceType;
use Modules\GdoTradeTerms\Models\GdoAccrualLine;
use Modules\GdoTradeTerms\Models\GdoAccrualRun;
use Modules\GdoTradeTerms\Models\GdoContract;
use Modules\GdoTradeTerms\Models\GdoPromotion;
use Modules\GdoTradeTerms\Models\GdoSettlement;
use Modules\GdoTradeTerms\Support\Money;

/**
 * Overview of the GDO trade terms of a company: contracts by status, what is accrued and not yet settled, the open and
 * disputed settlements, the latest accrual run and the year-end projection of the contracts that are running.
 */
final readonly class DashboardService
{
    private const int ProjectionLimit = 8;

    public function __construct(private ContractProgressService $progress) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(string $tenantId, Carbon $asOf): array
    {
        $byStatus = [];

        foreach (ContractStatus::cases() as $status) {
            $byStatus[$status->value] = GdoContract::query()->where('tenant_id', $tenantId)->where('status', $status->value)->count();
        }

        $accrued = static fn (SourceType $type): string => Money::from(GdoAccrualLine::query()->where('tenant_id', $tenantId)->where('source_type', $type->value)->where('status', LineStatus::Accrued->value)->sum('delta_amount'));
        $latest = GdoAccrualRun::query()->where('tenant_id', $tenantId)->where('status', RunStatus::Posted->value)->orderByDesc('period_key')->first();

        $running = GdoContract::query()
            ->where('tenant_id', $tenantId)
            ->where('status', ContractStatus::Active->value)
            ->whereDate('starts_on', '<=', $asOf->toDateString())
            ->whereDate('ends_on', '>=', $asOf->toDateString())
            ->with(['scales.tiers', 'promotions'])
            ->orderBy('ends_on')
            ->limit(self::ProjectionLimit)
            ->get();

        $projections = [];

        foreach ($running as $contract) {
            $progress = $this->progress->progress($contract, $asOf);
            $projections[] = [
                'contract_id' => $contract->id,
                'code' => $contract->code,
                'name' => $contract->name,
                'currency' => $contract->currency,
                'ends_on' => $contract->ends_on->toDateString(),
                'earned_to_date' => $progress['totals']['earned_total'],
                'projected_scales' => $progress['totals']['projected_scales'],
                'accrued' => $progress['totals']['accrued_total'],
            ];
        }

        return [
            'as_of' => $asOf->toDateString(),
            'contracts_by_status' => $byStatus,
            'active_promotions' => GdoPromotion::query()->where('tenant_id', $tenantId)->where('status', PromotionStatus::Active->value)->whereDate('ends_on', '>=', $asOf->toDateString())->count(),
            'accrued_unsettled' => ['scales' => $accrued(SourceType::Scale), 'promotions' => $accrued(SourceType::Promotion)],
            'open_settlements' => GdoSettlement::query()->where('tenant_id', $tenantId)->where('status', SettlementStatus::Proposed->value)->count(),
            'disputed_settlements' => GdoSettlement::query()->where('tenant_id', $tenantId)->where('status', SettlementStatus::Disputed->value)->count(),
            'latest_run' => $latest === null ? null : ['id' => $latest->id, 'period_key' => $latest->period_key, 'total_accrued' => Money::round((string) $latest->total_accrued), 'posted_at' => $latest->posted_at?->toIso8601String()],
            'projections' => $projections,
        ];
    }
}
