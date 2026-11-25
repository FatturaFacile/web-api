<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Services;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\LiquidityPlanning\Models\LiquidityAlert;
use Modules\LiquidityPlanning\Models\LiquiditySettings;
use Modules\LiquidityPlanning\Sources\FlowSource;
use Modules\LiquidityPlanning\Sources\SourceContext;
use Modules\LiquidityPlanning\Support\Levers;
use Modules\LiquidityPlanning\Support\ProjectionBuilder;
use Modules\LiquidityPlanning\Support\ScenarioApplier;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\CashRegister;

/**
 * The rolling cash forecast of the tenant: opening cash, the flows of the three reliability tiers read from the ledgers and
 * the manual items, the baseline projection and the what-if projection, the horizon summaries (30, 60, 90 and 180 days) and
 * the crisis indicators of both. Computed on request from the live records; nothing but the alerts is stored.
 */
final readonly class ForecastService
{
    /**
     * @param  list<FlowSource>  $sources
     */
    public function __construct(
        private array $sources,
        private ProjectionBuilder $builder,
        private ScenarioApplier $applier,
        private IndicatorService $indicators,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(Tenant $tenant, ?Levers $levers = null, ?CarbonImmutable $asOf = null, ?array $scenarioInfo = null): array
    {
        $core = $this->core($tenant, $levers, $asOf);
        $alerts = LiquidityAlert::query()->whereIn('status', [LiquidityAlert::Open, LiquidityAlert::Acknowledged])->orderByDesc('detected_at')->get();

        return [
            'as_of' => $core['start']->toDateString(),
            'currency' => $core['currency'],
            'jurisdiction' => $core['settings']->jurisdiction,
            'opening_cash' => $core['opening']['total'],
            'accounts' => $core['opening']['accounts'],
            'baseline' => $core['baseline'],
            'scenario' => $core['scenario'],
            'scenario_info' => $core['scenario'] === null ? null : ($scenarioInfo ?? ['id' => null, 'name' => null]) + ['levers' => $levers?->toArray()],
            'horizons' => array_map(
                fn (array $summary, int $index): array => ['days' => $summary['days'], 'baseline' => $summary, 'scenario' => $core['scenarioHorizons'][$index] ?? null],
                $core['baselineHorizons'],
                array_keys($core['baselineHorizons']),
            ),
            'indicators' => ['baseline' => $core['baselineIndicators'], 'scenario' => $core['scenarioIndicators']],
            'alerts' => $alerts->map(static fn (LiquidityAlert $alert): array => AlertService::present($alert))->all(),
        ];
    }

    /**
     * The baseline computation without presentation: used by the alert evaluation, which must not depend on a scenario.
     *
     * @return array<string, mixed>
     */
    public function core(Tenant $tenant, ?Levers $levers = null, ?CarbonImmutable $asOf = null): array
    {
        $start = ($asOf ?? CarbonImmutable::today())->startOfDay();
        $settings = LiquiditySettings::current((string) $tenant->country);
        $currency = strtoupper(TenantAccountingCurrency::of((string) $tenant->id));
        $opening = $this->openingCash($currency);
        $horizon = ProjectionBuilder::MaxDays;
        $context = new SourceContext((string) $tenant->id, $currency, $start, $start->addDays($horizon), $settings);
        $flows = [];

        foreach ($this->sources as $source) {
            foreach ($source->flows($context) as $flow) {
                $flows[] = $flow;
            }
        }

        $position = $this->indicators->position((string) $tenant->id, $start);
        $baseline = $this->builder->build($opening['total'], $flows, $start, $horizon);
        $baselineHorizons = $this->builder->horizons($opening['total'], $baseline['daily']);
        $baselineIndicators = $this->indicators->compute($flows, $baseline, $opening['total'], $start, $horizon, $settings, $position);
        $scenario = null;
        $scenarioHorizons = [];
        $scenarioIndicators = null;

        if ($levers !== null && ! $levers->isNeutral()) {
            $changed = $this->applier->apply($flows, $levers, $start);
            $scenario = $this->builder->build($opening['total'], $changed, $start, $horizon);
            $scenarioHorizons = $this->builder->horizons($opening['total'], $scenario['daily']);
            $delta = Decimal::sub($this->indicators->operatingCashFlow($changed), $this->indicators->operatingCashFlow($flows));
            $annualDelta = Decimal::mul($delta, Decimal::div('365', (string) $horizon));
            $scenarioIndicators = $this->indicators->compute($changed, $scenario, $opening['total'], $start, $horizon, $settings, $position, $annualDelta);
        }

        return [
            'start' => $start,
            'currency' => $currency,
            'settings' => $settings,
            'opening' => $opening,
            'flows' => $flows,
            'baseline' => $baseline,
            'baselineHorizons' => $baselineHorizons,
            'baselineIndicators' => $baselineIndicators,
            'scenario' => $scenario,
            'scenarioHorizons' => $scenarioHorizons,
            'scenarioIndicators' => $scenarioIndicators,
            'position' => $position,
        ];
    }

    /**
     * The balances of the active bank accounts and cash registers in the currency of the forecast.
     *
     * @return array{total: string, accounts: list<array<string, mixed>>}
     */
    private function openingCash(string $currency): array
    {
        $total = '0';
        $accounts = [];

        foreach (BankAccount::query()->where('is_active', true)->where('currency', $currency)->orderBy('bank_name')->get() as $account) {
            $total = Decimal::add($total, (string) $account->current_balance);
            $accounts[] = ['id' => (string) $account->id, 'bank_name' => (string) $account->bank_name, 'iban' => $account->iban, 'currency' => $currency, 'balance' => Decimal::round2((string) $account->current_balance)];
        }

        foreach (CashRegister::query()->where('is_active', true)->where('currency', $currency)->orderBy('code')->get() as $register) {
            $total = Decimal::add($total, (string) $register->current_balance);
            $accounts[] = ['id' => (string) $register->id, 'bank_name' => (string) $register->code, 'iban' => null, 'currency' => $currency, 'balance' => Decimal::round2((string) $register->current_balance)];
        }

        return ['total' => Decimal::round2($total), 'accounts' => $accounts];
    }
}
