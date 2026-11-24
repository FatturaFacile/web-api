<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Services;

use Modules\AgentCommissions\Domain\Enums\AgentType;
use Modules\AgentCommissions\Domain\Enums\EnasarcoMandate;
use Modules\AgentCommissions\Domain\Enums\FiscalRegime;
use Modules\AgentCommissions\Models\AgentProfile;
use Modules\AgentCommissions\Models\AgentYearBalance;
use Modules\Payroll\Calculation\Dec;

/**
 * Italian agent fiscal engine: Enasarco contribution (company and agent share) with the yearly ceiling (massimale) and
 * floor (minimale), FIRR accrual, withholding tax and VAT on a settlement. Every figure comes from
 * FiscalParameterResolver; the engine only applies it.
 *
 * Contributions are computed on the cumulative base of the year and the delta against what was already applied is
 * returned, so rounding never drifts and the invoice that crosses the massimale is split exactly at the boundary:
 * only the part below the ceiling is contributed, the rest is zero.
 */
final class FiscalEngine
{
    public function __construct(private readonly FiscalParameterResolver $parameters) {}

    public function mandateOf(AgentProfile $profile): string
    {
        return match ($profile->agent_type) {
            AgentType::SingleMandate->value => EnasarcoMandate::Single->value,
            AgentType::MultiMandate->value => EnasarcoMandate::Multi->value,
            default => $profile->enasarco_mandate ?? EnasarcoMandate::Multi->value,
        };
    }

    public function isItalianRegime(AgentProfile $profile): bool
    {
        return in_array($profile->fiscal_regime, [FiscalRegime::Enasarco->value, FiscalRegime::Aec->value], true);
    }

    /**
     * FIRR over a cumulative yearly commission, progressive by bracket.
     *
     * @param  list<array{up_to: string|null, rate: string}>  $brackets
     */
    public static function firrOn(string $cumulative, array $brackets): string
    {
        $total = '0';
        $previous = '0';

        foreach ($brackets as $bracket) {
            if (Dec::cmp($cumulative, $previous) <= 0) {
                break;
            }

            $top = $bracket['up_to'] === null ? $cumulative : Dec::min($cumulative, $bracket['up_to']);
            $total = Dec::add($total, Dec::div(Dec::mul(Dec::sub($top, $previous), $bracket['rate']), '100'));
            $previous = $top;
        }

        return Dec::round($total);
    }

    /**
     * What a settlement of `$gross` (the sum of the statement lines, negative for a net clawback) changes: the
     * contributions, FIRR, withholding and VAT of the delta, and the new cumulative figures of the year.
     *
     * @return array<string, mixed>
     */
    public function settle(string $tenantId, AgentProfile $profile, int $year, string $gross, AgentYearBalance $balance): array
    {
        $this->parameters->flush();
        $italian = $this->isItalianRegime($profile);
        $mandate = $this->mandateOf($profile);
        $zero = '0.00';
        $result = [
            'mandate' => $mandate,
            'applies_enasarco' => $italian,
            'enasarco_base' => $zero,
            'enasarco_agent' => $zero,
            'enasarco_company' => $zero,
            'firr' => $zero,
            'withholding_base' => $zero,
            'withholding' => $zero,
            'vat' => $zero,
            'massimale' => null,
            'minimale' => null,
            'massimale_reached' => false,
            'minimale_reached' => false,
            'minimale_shortfall' => $zero,
            'commission_ytd_after' => Dec::round(Dec::add((string) $balance->commission_ytd, $gross)),
            'enasarco_base_ytd_after' => (string) $balance->enasarco_base_ytd,
            'enasarco_agent_ytd_after' => (string) $balance->enasarco_agent_ytd,
            'enasarco_company_ytd_after' => (string) $balance->enasarco_company_ytd,
            'firr_ytd_after' => (string) $balance->firr_ytd,
        ];

        if (! $italian) {
            return $result;
        }

        $massimale = $this->parameters->value($tenantId, 'enasarco_massimale', $mandate, $year);
        $minimale = $this->parameters->value($tenantId, 'enasarco_minimale', $mandate, $year);
        $agentRate = $this->parameters->value($tenantId, 'enasarco_agent_rate', $mandate, $year);
        $companyRate = $this->parameters->value($tenantId, 'enasarco_company_rate', $mandate, $year);

        $before = (string) $balance->enasarco_base_ytd;
        $delta = Dec::isNegative($gross)
            ? Dec::neg(Dec::min(Dec::neg($gross), $before))
            : Dec::max('0', Dec::min($gross, Dec::max('0', Dec::sub($massimale, $before))));
        $after = Dec::add($before, $delta);

        $agentAfter = Dec::round(Dec::div(Dec::mul($after, $agentRate), '100'));
        $companyAfter = Dec::round(Dec::div(Dec::mul($after, $companyRate), '100'));
        $agentDelta = Dec::sub($agentAfter, (string) $balance->enasarco_agent_ytd);
        $companyDelta = Dec::sub($companyAfter, (string) $balance->enasarco_company_ytd);
        $totalContribution = Dec::add($agentAfter, $companyAfter);

        $result['enasarco_base'] = Dec::round($delta);
        $result['enasarco_agent'] = Dec::round($agentDelta);
        $result['enasarco_company'] = Dec::round($companyDelta);
        $result['enasarco_agent_rate'] = Dec::round($agentRate, 4);
        $result['enasarco_company_rate'] = Dec::round($companyRate, 4);
        $result['massimale'] = Dec::round($massimale);
        $result['minimale'] = Dec::round($minimale);
        $result['massimale_reached'] = Dec::cmp($after, $massimale) >= 0;
        $result['minimale_reached'] = Dec::cmp($totalContribution, $minimale) >= 0;
        $result['minimale_shortfall'] = Dec::round(Dec::max('0', Dec::sub($minimale, $totalContribution)));
        $result['enasarco_base_ytd_after'] = Dec::round($after);
        $result['enasarco_agent_ytd_after'] = $agentAfter;
        $result['enasarco_company_ytd_after'] = $companyAfter;

        if ($profile->firr_enabled) {
            $brackets = $this->parameters->brackets($tenantId, 'firr_brackets', $mandate, $year);
            $cumulativeBefore = Dec::max('0', (string) $balance->commission_ytd);
            $cumulativeAfter = Dec::max('0', $result['commission_ytd_after']);
            $firrDelta = Dec::sub(self::firrOn($cumulativeAfter, $brackets), self::firrOn($cumulativeBefore, $brackets));
            $result['firr'] = Dec::round($firrDelta);
            $result['firr_ytd_after'] = Dec::round(Dec::add((string) $balance->firr_ytd, $firrDelta));
        }

        $basePercent = $this->parameters->value(
            $tenantId,
            $profile->has_own_organization ? 'withholding_base_with_organization' : 'withholding_base_without_organization',
            null,
            $year,
        );
        $withholdingBase = Dec::round(Dec::div(Dec::mul(Dec::sub($gross, $result['enasarco_agent']), $basePercent), '100'));
        $result['withholding_base'] = $withholdingBase;
        $withholdingRate = $this->parameters->value($tenantId, 'withholding_rate', null, $year);
        $result['withholding_rate'] = Dec::round($withholdingRate, 4);
        $result['withholding'] = Dec::round(Dec::div(Dec::mul($withholdingBase, $withholdingRate), '100'));

        if ($profile->vat_applies) {
            $vatRate = $this->parameters->value($tenantId, 'vat_rate', null, $year);
            $result['vat_rate'] = Dec::round($vatRate, 4);
            $result['vat'] = Dec::round(Dec::div(Dec::mul($gross, $vatRate), '100'));
        }

        return $result;
    }

    /**
     * Net cash due to the agent: commission plus VAT, less the agent Enasarco share and the withholding.
     *
     * @param  array<string, mixed>  $fiscal
     */
    public static function netPayable(string $gross, array $fiscal): string
    {
        return Dec::round(Dec::sub(Dec::sub(Dec::add($gross, (string) $fiscal['vat']), (string) $fiscal['enasarco_agent']), (string) $fiscal['withholding']));
    }
}
