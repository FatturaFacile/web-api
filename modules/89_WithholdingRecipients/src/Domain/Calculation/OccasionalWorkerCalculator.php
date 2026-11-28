<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Domain\Calculation;

use Modules\WithholdingRecipients\Domain\Enums\RecipientRegime;
use Modules\WithholdingRecipients\Domain\ValueObjects\Money;

/**
 * Occasional self-employment (Art. 2222 c.c., Art. 67 c. 1 lett. l TUIR): 20% withholding on the whole amount, a EUR 2.00
 * revenue stamp on the receipt above EUR 77.47, and INPS Gestione Separata only on the part of the year's income (this
 * company plus what the payee certified from other clients) above EUR 5,000: one third to the worker, two thirds to the
 * company.
 */
final class OccasionalWorkerCalculator implements CompensationCalculator
{
    public function regime(): RecipientRegime
    {
        return RecipientRegime::OccasionalWorker;
    }

    public function calculate(CompensationInput $input): CompensationResult
    {
        $config = (array) config('withholding_recipients.occasional');
        $result = new ResultBuilder($input);

        $result->withholdingRate = (string) $config['withholding_rate'];
        $result->withholding = $input->gross->percent($config['withholding_rate']);
        $result->cuCausal = (string) $config['cu_causal'];

        if ($input->gross->greaterThan(Money::of($config['stamp_threshold']))) {
            $result->stampDuty = Money::of($config['stamp_amount']);
            $result->notes[] = 'revenue_stamp_applied';
        }

        $threshold = Money::of($config['inps_threshold']);
        $before = $input->priorCompanyIncome->plus($input->thirdPartyIncome);
        $after = $before->plus($input->gross);
        $result->inpsBase = $after->minus($threshold)->floorAtZero()->minus($before->minus($threshold)->floorAtZero());

        if ($result->inpsBase->isPositive()) {
            $result->inpsTotal = $result->inpsBase->percent(InpsRate::forYear($config['inps_rate'], $input->paymentDate->year));
            [$result->inpsWorker, $result->inpsCompany] = $result->inpsTotal->split($config['inps_worker_share']);
            $result->warnings[] = 'inps_threshold_exceeded';
        }

        $result->threshold('occasional_5000', $after->min($threshold), $threshold);

        return $result->build();
    }
}
