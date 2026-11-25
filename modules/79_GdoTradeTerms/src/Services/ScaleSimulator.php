<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Services;

use Modules\GdoTradeTerms\Domain\Enums\TierMode;
use Modules\GdoTradeTerms\Domain\Enums\VolumeMode;
use Modules\GdoTradeTerms\Support\Money;

/**
 * What-if of a rebate scale: the rebate that a list of volumes earns under the retroactive and the progressive reading of the
 * same brackets, and the cliff each threshold creates. Nothing is stored.
 */
final readonly class ScaleSimulator
{
    public function __construct(private RebateScaleCalculator $calculator) {}

    /**
     * @param  list<array{threshold: string, rate_percent: string, flat_amount?: string|null}>  $tiers
     * @param  list<string>  $volumes
     * @return array<string, mixed>
     */
    public function simulate(string $tierMode, string $volumeMode, string $baseline, array $tiers, array $volumes): array
    {
        $selected = TierMode::from($tierMode);
        $volumeKind = VolumeMode::from($volumeMode);
        $sorted = $this->calculator->sorted($tiers);
        $rows = [];

        foreach ($volumes as $volume) {
            $qualifying = $this->calculator->qualifying($volume, $baseline, $volumeKind);
            $retroactive = $this->calculator->earn($sorted, $qualifying, TierMode::Retroactive);
            $progressive = $this->calculator->earn($sorted, $qualifying, TierMode::Progressive);
            $chosen = $selected === TierMode::Retroactive ? $retroactive : $progressive;

            $rows[] = [
                'volume' => Money::round($volume),
                'qualifying' => $qualifying,
                'retroactive' => $retroactive,
                'progressive' => $progressive,
                'selected' => $chosen,
                'effective_percent' => Money::isPositive($volume) ? Money::div(Money::mul($chosen['rebate'], '100'), $volume) : Money::round('0'),
            ];
        }

        $cliffs = [];

        foreach ($sorted as $tier) {
            if (! Money::isPositive($tier['threshold'])) {
                continue;
            }

            $at = $this->calculator->earn($sorted, $tier['threshold'], $selected)['rebate'];
            $below = $this->calculator->earn($sorted, Money::sub($tier['threshold'], '0.0001'), $selected)['rebate'];
            $cliffs[] = ['threshold' => Money::round($tier['threshold']), 'rebate_below' => $below, 'rebate_at' => $at, 'jump' => Money::sub($at, $below)];
        }

        return ['tier_mode' => $selected->value, 'volume_mode' => $volumeKind->value, 'baseline' => Money::round($baseline), 'rows' => $rows, 'cliffs' => $cliffs];
    }
}
