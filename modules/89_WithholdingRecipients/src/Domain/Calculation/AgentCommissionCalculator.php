<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Domain\Calculation;

use Modules\WithholdingRecipients\Domain\Enums\EnasarcoMandate;
use Modules\WithholdingRecipients\Domain\Enums\RecipientRegime;
use Modules\WithholdingRecipients\Domain\ValueObjects\Money;

/**
 * Commissions of agents and sales representatives (Art. 25-bis D.P.R. 600/1973): 23% on 50% of the commission (11.50%
 * effective) or on 20% when the agent certified the continuous use of employees or third parties (4.60% effective).
 * Enasarco is split between agent and company within the yearly ceiling; FIRR accrues on progressive brackets at the
 * company's cost.
 */
final class AgentCommissionCalculator implements CompensationCalculator
{
    public function regime(): RecipientRegime
    {
        return RecipientRegime::Agent25Bis;
    }

    public function calculate(CompensationInput $input): CompensationResult
    {
        $config = (array) config('withholding_recipients.agent');
        $result = new ResultBuilder($input);

        $basePercent = $input->hasReducedWithholdingCertificate ? $config['reduced_base_percent'] : $config['base_percent'];
        $result->taxableBase = $input->gross->percent($basePercent);
        $result->flatRateDeduction = $input->gross->minus($result->taxableBase);
        $result->withholdingRate = (string) $config['withholding_rate'];
        $result->withholding = $result->taxableBase->percent($config['withholding_rate']);
        $result->nonTaxable = $result->flatRateDeduction;
        $result->cuCausal = $this->regime()->defaultCuCausal($input->enasarcoMandate);
        $result->notes[] = $input->hasReducedWithholdingCertificate ? 'agent_reduced_base_20' : 'agent_standard_base_50';

        if (! $input->hasReducedWithholdingCertificate) {
            $result->notes[] = 'agent_certificate_missing';
        }

        if ($input->enasarcoMandate !== null) {
            $this->enasarco($input, $input->enasarcoMandate, $config['enasarco'], $result);
        } else {
            $result->warnings[] = 'enasarco_mandate_missing';
        }

        if ($input->firrEnabled) {
            $brackets = $config['firr_brackets'];
            $before = Brackets::marginal($input->priorCompanyIncome, $brackets);
            $after = Brackets::marginal($input->priorCompanyIncome->plus($input->gross), $brackets);
            $result->firrAccrual = $after->minus($before);
        }

        return $result->build();
    }

    /**
     * @param  array<string, mixed>  $enasarco
     */
    private function enasarco(CompensationInput $input, EnasarcoMandate $mandate, array $enasarco, ResultBuilder $result): void
    {
        $ceiling = Money::of($enasarco['ceiling'][$mandate->value]);
        $room = $ceiling->minus($input->priorCompanyIncome)->floorAtZero();
        $result->enasarcoBase = $input->gross->min($room);
        $result->enasarcoAgent = $result->enasarcoBase->percent($enasarco['agent_rate']);
        $result->enasarcoCompany = $result->enasarcoBase->percent($enasarco['company_rate']);
        $result->threshold('enasarco_ceiling', $input->priorCompanyIncome->plus($input->gross)->min($ceiling), $ceiling);

        if ($input->priorCompanyIncome->plus($input->gross)->greaterThan($ceiling)) {
            $result->warnings[] = 'enasarco_ceiling_reached';
        }
    }
}
