<?php

declare(strict_types=1);

namespace Modules\Rebates\Services;

use Illuminate\Support\Carbon;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Rebates\Models\RebateAccrual;
use Modules\Rebates\Models\RebateAgreement;
use Modules\Rebates\Models\RebateContribution;

/**
 * Live position of a rebate agreement: the qualifying spend up to the date, the tier it stands in, the rebate accrued to
 * date, the exact distance to the next tier (or to the bonus target) and what reaching it would earn. Read only: it
 * computes from the invoices and writes nothing.
 */
final readonly class SupplierRebateProgressService
{
    public function __construct(private RebateAccrualService $accruals, private RebateTierCalculator $calculator) {}

    /**
     * @return array<string, mixed>
     */
    public function progress(RebateAgreement $agreement, Carbon $asOf): array
    {
        $asOf = $asOf->copy()->startOfDay();
        $capped = $asOf->gt($agreement->ends_on) ? $agreement->ends_on->copy() : $asOf;
        $volume = $asOf->lt($agreement->starts_on) ? '0.00' : $this->accruals->volume($agreement, $capped);

        $tiers = $agreement->tiers->map(static fn ($tier): array => ['threshold_amount' => (string) $tier->threshold_amount, 'rate_percent' => (string) $tier->rate_percent, 'flat_amount' => $tier->flat_amount === null ? null : (string) $tier->flat_amount])->all();
        $tierProgress = $agreement->rebate_type === RebateAgreement::TieredVolume ? $this->calculator->progress($tiers, $volume, $agreement->tier_mode) : null;
        $bonus = $agreement->rebate_type === RebateAgreement::FixedBonus ? $this->bonusProgress($agreement, $volume) : null;
        $earned = $this->accruals->earned($agreement, $volume, $capped)['rebate'];

        return [
            'agreement_id' => (string) $agreement->id,
            'rebate_type' => $agreement->rebate_type,
            'tier_mode' => $agreement->tier_mode,
            'currency' => $agreement->currency,
            'as_of' => $capped->toDateString(),
            'qualifying_volume' => $volume,
            'earned_rebate' => $earned,
            'accrued_rebate' => Decimal::round2((string) RebateAccrual::query()->where('agreement_id', $agreement->id)->whereNotIn('status', [RebateAccrual::Reversed])->sum('rebate_amount')),
            'settled_rebate' => Decimal::round2((string) RebateAccrual::query()->where('agreement_id', $agreement->id)->where('status', RebateAccrual::Settled)->sum('rebate_amount')),
            'tier' => $tierProgress,
            'bonus' => $bonus,
            'contributions' => $this->contributionSummary($agreement),
            'tiers' => $this->scale($tiers),
        ];
    }

    /**
     * @return array{target: string, bonus: string, reached: bool, distance_to_target: string, progress_percent: string}
     */
    private function bonusProgress(RebateAgreement $agreement, string $volume): array
    {
        $target = (string) ($agreement->fixed_bonus_target ?? '0');
        $reached = Decimal::isPositive($volume) && Decimal::compare($volume, $target) >= 0;
        $percent = Decimal::isPositive($target) ? Decimal::round2(Decimal::div(Decimal::mul($volume, '100'), $target)) : '100.00';

        return [
            'target' => $target,
            'bonus' => (string) ($agreement->fixed_bonus_amount ?? '0.00'),
            'reached' => $reached,
            'distance_to_target' => $reached ? '0.00' : Decimal::round2(Decimal::sub($target, $volume)),
            'progress_percent' => Decimal::compare($percent, '100') > 0 ? '100.00' : (Decimal::isNegative($percent) ? '0.00' : $percent),
        ];
    }

    /**
     * @return array{planned: string, accrued: string, settled: string}
     */
    private function contributionSummary(RebateAgreement $agreement): array
    {
        $sum = static fn (string $status): string => Decimal::round2((string) RebateContribution::query()->where('agreement_id', $agreement->id)->where('status', $status)->sum('amount'));

        return ['planned' => $sum(RebateContribution::Planned), 'accrued' => $sum(RebateContribution::Accrued), 'settled' => $sum(RebateContribution::Settled)];
    }

    /**
     * The scale with the upper bound of every tier: the threshold of the next one.
     *
     * @param  list<array{threshold_amount: string, rate_percent: string, flat_amount: string|null}>  $tiers
     * @return list<array{threshold_amount: string, max_threshold: string|null, rate_percent: string, flat_amount: string|null}>
     */
    private function scale(array $tiers): array
    {
        usort($tiers, static fn (array $left, array $right): int => Decimal::compare($left['threshold_amount'], $right['threshold_amount']));

        return array_map(static fn (array $tier, int $index): array => [
            'threshold_amount' => $tier['threshold_amount'],
            'max_threshold' => $tiers[$index + 1]['threshold_amount'] ?? null,
            'rate_percent' => $tier['rate_percent'],
            'flat_amount' => $tier['flat_amount'],
        ], $tiers, array_keys($tiers));
    }
}
