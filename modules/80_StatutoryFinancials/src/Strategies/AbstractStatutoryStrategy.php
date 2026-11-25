<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Strategies;

use Modules\StatutoryFinancials\Contracts\IStatutoryReportingComplianceStrategy;
use Modules\StatutoryFinancials\Domain\Enums\AccountingStandard;
use Modules\StatutoryFinancials\Domain\Strategy\FinancialSnapshot;
use Modules\StatutoryFinancials\Domain\Strategy\ProfitAllocationInput;
use Modules\StatutoryFinancials\Domain\Strategy\XbrlProfile;
use Modules\StatutoryFinancials\Services\ProfitAllocationCalculator;
use Modules\StatutoryFinancials\Services\RatioEngine;
use Modules\StatutoryFinancials\Support\Money;

/**
 * What the Italian and the Swiss strategies share: the what-if simulation of the crisis indicators, the profit allocation
 * calculator and the XBRL profile read from the configuration.
 */
abstract class AbstractStatutoryStrategy implements IStatutoryReportingComplianceStrategy
{
    public function __construct(protected readonly RatioEngine $ratios, protected readonly ProfitAllocationCalculator $allocation) {}

    abstract protected function allocationArticle(): string;

    /**
     * Family of the seeded ratio definitions used besides the shared ones ("IT" or "CH").
     */
    abstract protected function indicatorFamily(): string;

    public function supports(AccountingStandard $standard): bool
    {
        return $standard->jurisdiction() === $this->jurisdiction();
    }

    public function profitAllocation(ProfitAllocationInput $input): array
    {
        return $this->allocation->calculate($input, $this->allocationArticle());
    }

    public function indicators(FinancialSnapshot $snapshot, array $whatIf = []): array
    {
        $simulated = $this->applyWhatIf($snapshot, $whatIf);
        $result = $this->ratios->compute($this->indicatorFamily(), $simulated);

        $horizon = (string) config('statutory_financials.dscr_horizon_months', 6);
        $result['dscr'] = [
            'horizon_months' => $horizon,
            'expected_cash_flow' => $simulated->get('expected_cash_flow_6m'),
            'opening_liquidity' => $simulated->get('opening_liquidity'),
            'debt_service' => $simulated->get('debt_service_6m'),
            'value' => $this->ratios->value('DSCR_6M', $simulated),
        ];
        $result['what_if'] = $whatIf;
        $result['inputs'] = $simulated->values;

        return $result;
    }

    public function xbrlProfile(AccountingStandard $standard): XbrlProfile
    {
        /** @var array{prefix: string, namespace: string, entry_point: string, version: string, entity_scheme: string} $config */
        $config = config('statutory_financials.taxonomy.'.$this->jurisdiction());

        return new XbrlProfile($config['prefix'], $config['namespace'], $config['entry_point'], $config['version'], $standard->jurisdiction() === 'CH' ? 'CHF' : 'EUR', $config['entity_scheme']);
    }

    /**
     * What-if parameters of the simulator, in percent or absolute amounts:
     *  ebitda_change_percent, revenue_change_percent, debt_service_change_percent, extra_liquidity,
     *  expected_cash_flow_6m, debt_service_6m, opening_liquidity (absolute overrides).
     *
     * @param  array<string, string>  $whatIf
     */
    protected function applyWhatIf(FinancialSnapshot $snapshot, array $whatIf): FinancialSnapshot
    {
        $values = [];
        $factor = static fn (string $key): string => Money::add('1', Money::div((string) ($whatIf[$key] ?? '0'), '100') ?? '0');

        if (isset($whatIf['ebitda_change_percent']) && $whatIf['ebitda_change_percent'] !== '') {
            $ebitda = Money::add($snapshot->get('ebit'), $snapshot->get('depreciation'));
            $delta = Money::sub(Money::mul($ebitda, $factor('ebitda_change_percent')), $ebitda);
            $values['ebit'] = Money::add($snapshot->get('ebit'), $delta);
            $values['net_profit'] = Money::add($snapshot->get('net_profit'), $delta);
            $values['expected_cash_flow_6m'] = Money::mul($snapshot->get('expected_cash_flow_6m'), $factor('ebitda_change_percent'));
        }

        if (isset($whatIf['revenue_change_percent']) && $whatIf['revenue_change_percent'] !== '') {
            $values['revenue'] = Money::mul($snapshot->get('revenue'), $factor('revenue_change_percent'));
        }

        if (isset($whatIf['debt_service_change_percent']) && $whatIf['debt_service_change_percent'] !== '') {
            $values['debt_service_6m'] = Money::mul($snapshot->get('debt_service_6m'), $factor('debt_service_change_percent'));
        }

        if (isset($whatIf['extra_liquidity']) && $whatIf['extra_liquidity'] !== '') {
            $values['opening_liquidity'] = Money::add($snapshot->get('opening_liquidity'), (string) $whatIf['extra_liquidity']);
        }

        foreach (['expected_cash_flow_6m', 'debt_service_6m', 'opening_liquidity'] as $key) {
            if (isset($whatIf[$key]) && $whatIf[$key] !== '') {
                $values[$key] = Money::from($whatIf[$key]);
            }
        }

        return $snapshot->with($values);
    }
}
