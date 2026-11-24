<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Services;

use Carbon\CarbonImmutable;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\AssetManagement\Support\Money;

/**
 * Pure calculator for Italian fiscal incentives on instrumental capital assets:
 * - Super-Ammortamento (extra fiscal deduction base, e.g. +30% / +40%)
 * - Iper-Ammortamento (Industry 4.0 extra fiscal deduction base, e.g. +150% / +170%)
 * - Transizione 4.0 (tax credit: 20% tangible / 15% intangible over 3 annual installments from interconnection)
 * - Transizione 5.0 (energy-efficiency tax credit: 35% / 40% / 45% configurable)
 */
final class FiscalIncentiveCalculator
{
    public const array Regimes = [
        'none',
        'super_ammortamento',
        'iper_ammortamento',
        'transition_4_0_tangible',
        'transition_4_0_intangible',
        'transition_5_0',
    ];

    /**
     * Default statutory percentage and installment years per incentive regime.
     *
     * @return array{rate: string, years: int, mode: 'tax_credit'|'extra_deduction'|'none'}
     */
    public function defaultsFor(string $regime): array
    {
        return match ($regime) {
            'super_ammortamento' => ['rate' => '30.0000', 'years' => 1, 'mode' => 'extra_deduction'],
            'iper_ammortamento' => ['rate' => '150.0000', 'years' => 1, 'mode' => 'extra_deduction'],
            'transition_4_0_tangible' => ['rate' => '20.0000', 'years' => 3, 'mode' => 'tax_credit'],
            'transition_4_0_intangible' => ['rate' => '15.0000', 'years' => 3, 'mode' => 'tax_credit'],
            'transition_5_0' => ['rate' => '35.0000', 'years' => 1, 'mode' => 'tax_credit'],
            default => ['rate' => '0.0000', 'years' => 0, 'mode' => 'none'],
        };
    }

    /**
     * Computes the total tax credit or extra fiscal deduction base for an asset.
     *
     * @return array{
     *   regime: string,
     *   mode: string,
     *   eligible: bool,
     *   interconnection_date: ?string,
     *   rate_percent: string,
     *   installment_years: int,
     *   tax_credit_total: string,
     *   extra_deduction_base: string,
     *   annual_installments: array<int, string>
     * }
     */
    public function computeSummary(FixedAsset $asset): array
    {
        $regime = (string) ($asset->incentive_regime ?: 'none');
        if (! in_array($regime, self::Regimes, true) || $regime === 'none') {
            return [
                'regime' => 'none',
                'mode' => 'none',
                'eligible' => false,
                'interconnection_date' => null,
                'rate_percent' => '0.0000',
                'installment_years' => 0,
                'tax_credit_total' => '0.00',
                'extra_deduction_base' => '0.00',
                'annual_installments' => [],
            ];
        }

        $defaults = $this->defaultsFor($regime);
        $rate = Money::isPositive($asset->incentive_rate_percent) ? Money::of($asset->incentive_rate_percent) : $defaults['rate'];
        $years = max(1, (int) ($asset->incentive_installment_years ?: $defaults['years']));
        $cost = Money::isPositive($asset->capitalized_cost) ? Money::round($asset->capitalized_cost) : Money::round($asset->acquisition_cost);
        $benefitTotal = Money::round(Money::div(Money::mul($cost, $rate), '100'));

        $interconnection = $asset->incentive_interconnection_date
            ?? $asset->in_service_date
            ?? $asset->capitalization_date
            ?? $asset->acquisition_date;
        $startYear = $interconnection !== null ? (int) CarbonImmutable::parse($interconnection->format('Y-m-d'))->year : (int) date('Y');

        if ($defaults['mode'] === 'extra_deduction') {
            return [
                'regime' => $regime,
                'mode' => 'extra_deduction',
                'eligible' => true,
                'interconnection_date' => $interconnection?->format('Y-m-d'),
                'rate_percent' => Money::round($rate, 4),
                'installment_years' => $years,
                'tax_credit_total' => '0.00',
                'extra_deduction_base' => $benefitTotal,
                'annual_installments' => [],
            ];
        }

        // Split tax credit evenly over N annual installments, assigning rounding remainder to the final installment.
        $installments = [];
        $baseInstallment = Money::round(Money::div($benefitTotal, (string) $years));
        $allocated = '0.00';

        for ($index = 0; $index < $years; $index++) {
            $year = $startYear + $index;
            if ($index === $years - 1) {
                $installments[$year] = Money::round(Money::sub($benefitTotal, $allocated));
            } else {
                $installments[$year] = $baseInstallment;
                $allocated = Money::add($allocated, $baseInstallment);
            }
        }

        return [
            'regime' => $regime,
            'mode' => 'tax_credit',
            'eligible' => true,
            'interconnection_date' => $interconnection?->format('Y-m-d'),
            'rate_percent' => Money::round($rate, 4),
            'installment_years' => $years,
            'tax_credit_total' => $benefitTotal,
            'extra_deduction_base' => '0.00',
            'annual_installments' => $installments,
        ];
    }

    /**
     * Extra fiscal depreciation deduction in a period for Super/Iper-Ammortamento, proportional to the fiscal charge.
     */
    public function extraDeductionForCharge(FixedAsset $asset, string $fiscalDepreciationAmount): string
    {
        $summary = $this->computeSummary($asset);
        if ($summary['mode'] !== 'extra_deduction' || ! Money::isPositive($summary['extra_deduction_base']) || ! Money::isPositive($fiscalDepreciationAmount)) {
            return '0.00';
        }

        return Money::round(Money::div(Money::mul($fiscalDepreciationAmount, $summary['rate_percent']), '100'));
    }
}
