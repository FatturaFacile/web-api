<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Domain\Calculation;

use Modules\WithholdingRecipients\Domain\Enums\RecipientRegime;
use Modules\WithholdingRecipients\Domain\ValueObjects\Money;

/**
 * Amateur sports work (D.Lgs. 36/2021), tiers on the cumulative income of the calendar year (this company plus the sports
 * income the payee certified from others):
 *  - up to EUR 5,000: exempt from INPS and IRPEF;
 *  - EUR 5,000.01 - 15,000: exempt from IRPEF, INPS Gestione Separata on a 50% base (until 2027), one third worker;
 *  - over EUR 15,000: also IRPEF withholding (progressive) on the amount above EUR 15,000.
 */
final class AmateurSportsCalculator implements CompensationCalculator
{
    public function regime(): RecipientRegime
    {
        return RecipientRegime::AmateurSports;
    }

    public function calculate(CompensationInput $input): CompensationResult
    {
        $config = (array) config('withholding_recipients.amateur_sports');
        $inps = (array) config('withholding_recipients.occasional');
        $result = new ResultBuilder($input);

        $exempt = Money::of($config['exempt_threshold']);
        $irpefFloor = Money::of($config['irpef_threshold']);
        $before = $input->priorCompanyIncome->plus($input->thirdPartyIncome);
        $after = $before->plus($input->gross);

        $exemptPortion = $after->min($exempt)->minus($before->min($exempt));
        $inpsPortion = $input->gross->minus($exemptPortion);
        $irpefPortion = $after->minus($irpefFloor)->floorAtZero()->minus($before->minus($irpefFloor)->floorAtZero());

        $basePercent = $input->taxYear <= (int) $config['inps_reduction_until_year'] ? $config['inps_base_percent_reduced'] : '100';
        $result->inpsBase = $inpsPortion->percent($basePercent);

        if ($result->inpsBase->isPositive()) {
            $rate = InpsRate::forYear((array) $inps['inps_rate'], $input->paymentDate->year);
            $result->inpsTotal = $result->inpsBase->percent($rate);
            [$result->inpsWorker, $result->inpsCompany] = $result->inpsTotal->split($inps['inps_worker_share']);
            $result->notes[] = $basePercent === '100' ? 'sports_inps_full_base' : 'sports_inps_half_base';
        }

        $brackets = (array) config('withholding_recipients.irpef_brackets');
        $result->taxableBase = $irpefPortion;
        $result->withholding = Brackets::marginal($after->minus($irpefFloor)->floorAtZero(), $brackets)
            ->minus(Brackets::marginal($before->minus($irpefFloor)->floorAtZero(), $brackets));
        $result->withholdingRate = $irpefPortion->isPositive() ? number_format($result->withholding->cents * 100 / $irpefPortion->cents, 2, '.', '') : '0';
        $result->flatRateDeduction = Money::zero();
        $result->nonTaxable = $input->gross->minus($irpefPortion);
        $result->nonTaxableCode = $result->nonTaxable->isPositive() ? (string) $config['non_taxable_code'] : null;
        $result->cuCausal = (string) $config['cu_causal'];

        if ($irpefPortion->isPositive()) {
            $result->warnings[] = 'sports_irpef_threshold_exceeded';
        } elseif ($inpsPortion->isPositive()) {
            $result->warnings[] = 'sports_inps_threshold_exceeded';
        }

        $result->threshold('sports_5000', $after->min($exempt), $exempt);
        $result->threshold('sports_15000', $after->min($irpefFloor), $irpefFloor);

        return $result->build();
    }
}
