<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Domain\Calculation;

use Modules\WithholdingRecipients\Domain\Enums\RecipientRegime;

/**
 * Minor wages paid by a Swiss employer: the CHF 2,300 a year exemption of Art. 85 OAVS, with the mandatory AVS / AI / IPG
 * from the first franc for cultural and artistic activities.
 */
final class SwissDeMinimisCalculator implements CompensationCalculator
{
    public function regime(): RecipientRegime
    {
        return RecipientRegime::SwissDeMinimis;
    }

    public function calculate(CompensationInput $input): CompensationResult
    {
        $result = new ResultBuilder($input);
        $result->withholdingRate = '0';
        AvsContribution::apply($input, $result);

        return $result->build();
    }
}
