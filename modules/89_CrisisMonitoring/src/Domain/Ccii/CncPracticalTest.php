<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Domain\Ccii;

use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * The CNC practical test of the D.M. 21 March 2023 on the reasonable feasibility of the turnaround: the total debt to be restructured
 * divided by the annual free cash flow available for the debt service, classified in the seeded tiers (ratio up to a bound).
 */
final readonly class CncPracticalTest
{
    /**
     * @param  list<array{code: string, label_key: string, ratio_up_to: string|null, status: string}>  $tiers  ordered by ascending bound, the open-ended tier last
     * @param  array<string, bool|null>  $checklist  check code => passed (null when it cannot be told)
     * @return array<string, mixed>
     */
    public function evaluate(string $debtToRestructure, string $annualFreeCashFlow, array $tiers, array $checklist): array
    {
        $debt = Decimal::isNegative($debtToRestructure) ? '0' : $debtToRestructure;
        $ratio = null;
        $tier = $tiers[count($tiers) - 1] ?? ['code' => 'unknown', 'label_key' => 'crisisApi.cnc.tier.unknown', 'ratio_up_to' => null, 'status' => 'red'];

        if (! Decimal::isPositive($debt)) {
            $tier = $tiers[0] ?? $tier;
        } elseif (Decimal::isPositive($annualFreeCashFlow)) {
            $ratio = Decimal::round2(Decimal::div($debt, $annualFreeCashFlow));

            foreach ($tiers as $candidate) {
                if ($candidate['ratio_up_to'] === null || Decimal::compare($ratio, $candidate['ratio_up_to']) <= 0) {
                    $tier = $candidate;

                    break;
                }
            }
        }

        $items = [];

        foreach ($checklist as $code => $passed) {
            $items[] = ['code' => $code, 'label_key' => 'crisisApi.cnc.check.'.$code, 'passed' => $passed];
        }

        return [
            'ratio' => $ratio,
            'debt_to_restructure' => Decimal::round2($debt),
            'annual_free_cash_flow' => Decimal::round2($annualFreeCashFlow),
            'tier_code' => $tier['code'],
            'tier_label_key' => $tier['label_key'],
            'status' => $tier['status'],
            'checklist' => $items,
        ];
    }
}
