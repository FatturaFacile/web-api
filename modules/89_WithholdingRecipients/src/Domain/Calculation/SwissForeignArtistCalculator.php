<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Domain\Calculation;

use Modules\WithholdingRecipients\Domain\Enums\ActivityType;
use Modules\WithholdingRecipients\Domain\Enums\RecipientRegime;
use Modules\WithholdingRecipients\Domain\ValueObjects\Money;

/**
 * Non-resident artists, athletes and speakers working in Switzerland (Art. 91-92 LIFD): the source tax (imposta alla fonte)
 * applies on the compensation less the acquisition expenses (documented, or the federal flat rate: 50% artists, 20%
 * athletes and speakers), at the rate of the bracket of the daily amount. AVS / AI / IPG follow Art. 85 OAVS.
 */
final class SwissForeignArtistCalculator implements CompensationCalculator
{
    public function regime(): RecipientRegime
    {
        return RecipientRegime::SwissForeignArtist;
    }

    public function calculate(CompensationInput $input): CompensationResult
    {
        $config = (array) config('withholding_recipients.swiss');
        $result = new ResultBuilder($input);

        $activity = $input->activityType ?? ActivityType::Artist;

        if ($input->acquisitionExpenses !== null && $input->acquisitionExpenses->isPositive()) {
            $expenses = $input->acquisitionExpenses->min($input->gross);
            $result->notes[] = 'source_tax_documented_expenses';
        } else {
            $expenses = $input->gross->percent($config['flat_expense_percent'][$activity->value]);
            $result->notes[] = 'source_tax_flat_expenses';
        }

        $days = max(1, $input->performanceDays);
        $result->acquisitionExpenses = $expenses;
        $result->flatRateDeduction = $expenses;
        $result->taxableBase = $input->gross->minus($expenses);
        $daily = Money::fromCents(intdiv($result->taxableBase->cents, $days));
        $result->withholdingRate = Brackets::rateFor($daily, $config['source_tax_brackets']);
        $result->sourceTax = $result->taxableBase->percent($result->withholdingRate);

        AvsContribution::apply($input, $result);
        $result->nonTaxable = Money::zero();

        return $result->build();
    }
}
