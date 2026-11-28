<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Domain\Calculation;

use Modules\WithholdingRecipients\Domain\ValueObjects\Money;

/**
 * AVS / AI / IPG on a minor wage (Art. 85 OAVS). A wage that stays within CHF 2,300 a year per employer is exempt, unless
 * the payee works in the cultural or artistic sector, where the contributions are due from the first franc. Once the
 * yearly total passes the limit the contributions are due on the whole wage, so the contributions not yet charged on the
 * earlier payments are caught up.
 */
final class AvsContribution
{
    public static function apply(CompensationInput $input, ResultBuilder $result): void
    {
        $limit = Money::of(config('withholding_recipients.swiss.de_minimis'));
        $rate = (string) config('withholding_recipients.swiss.avs_rate');
        $total = $input->priorCompanyIncome->plus($input->gross);

        if ($input->culturalSector) {
            $base = $input->gross;
            $result->notes[] = 'avs_cultural_sector_from_first_franc';
        } elseif ($total->greaterThan($limit)) {
            $base = $total->minus($input->priorAvsBase)->floorAtZero();
            $result->warnings[] = 'avs_de_minimis_exceeded';
        } else {
            $base = Money::zero();
            $result->notes[] = 'avs_de_minimis_exempt';
        }

        $result->avsBase = $base;
        $contribution = $base->percent($rate);
        [$result->avsEmployee, $result->avsEmployer] = $contribution->split('50');
        $result->nonTaxable = $input->gross->minus($input->gross->min($base));
        $result->threshold('avs_2300', $total->min($limit), $limit);
    }
}
