<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/LiquiditySupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    lpSetUp($this);
});

it('projects the opening cash, the ledgers and the manual items in their tiers', function (): void {
    lpBank($this, '10000.00');
    lpInvoice($this, 'FT-1', '3000.00', 10);
    treasurySupplierInvoice($this->tenant, apSupplierFor($this), 'FA-1', '1200.00', ['due_date' => now()->addDays(5)->toDateString()]);
    lpManualItem($this, 'outflow', 'tax', '500.00', 20, 'estimated');

    $data = $this->getJson('/api/v1/liquidity-planning/overview')->assertOk()->json('data');

    expect($data['currency'])->toBe('EUR')
        ->and($data['opening_cash'])->toBe('10000.00')
        ->and($data['baseline']['daily'])->toHaveCount(181)
        ->and($data['baseline']['daily'][5]['tiers']['certain']['outflows'])->toBe('1200.00')
        ->and($data['baseline']['daily'][10]['tiers']['certain']['inflows'])->toBe('3000.00')
        ->and($data['baseline']['daily'][20]['tiers']['estimated']['outflows'])->toBe('500.00')
        ->and(array_column($data['horizons'], 'days'))->toBe([30, 60, 90, 180])
        ->and($data['horizons'][0]['baseline']['closing_balance'])->toBe('11300.00')
        ->and($data['horizons'][0]['scenario'])->toBeNull()
        ->and($data['scenario'])->toBeNull()
        ->and($data['baseline']['negative_balance'])->toBeNull();
});

it('returns a graceful empty forecast when nothing is registered', function (): void {
    $data = $this->getJson('/api/v1/liquidity-planning/overview')->assertOk()->json('data');

    expect($data['opening_cash'])->toBe('0.00')
        ->and($data['baseline']['daily'][180]['closing'])->toBe('0.00')
        ->and($data['indicators']['baseline']['dscr']['status'])->toBe('not_applicable')
        ->and($data['indicators']['baseline']['dscr']['value'])->toBeNull()
        ->and($data['alerts'])->toBe([]);
});

it('simulates a what-if scenario next to the baseline without saving anything', function (): void {
    lpBank($this, '10000.00');
    lpInvoice($this, 'FT-1', '3000.00', 10);
    lpManualItem($this, 'outflow', 'payable', '500.00', 20);

    $data = $this->getJson('/api/v1/liquidity-planning/overview?levers[collection_delay_days]=60&levers[raw_material_increase_percent]=10')->assertOk()->json('data');

    expect($data['horizons'][0]['baseline']['closing_balance'])->toBe('12500.00')
        ->and($data['horizons'][0]['scenario']['closing_balance'])->toBe('9450.00')
        ->and($data['horizons'][3]['scenario']['closing_balance'])->toBe('12450.00')
        ->and($data['scenario']['daily'])->toHaveCount(181)
        ->and($data['indicators']['scenario'])->not->toBeNull()
        ->and($data['scenario_info']['levers']['collection_delay_days'])->toBe(60);
});

it('computes the six-month DSCR with its analytical lines', function (): void {
    lpManualItem($this, 'inflow', 'receivable', '1500.00', 10);
    lpManualItem($this, 'outflow', 'debt_principal', '1000.00', 20);
    lpManualItem($this, 'outflow', 'debt_interest', '100.00', 20);

    $dscr = $this->getJson('/api/v1/liquidity-planning/overview')->assertOk()->json('data.indicators.baseline.dscr');

    expect($dscr['value'])->toBe('1.36')
        ->and($dscr['status'])->toBe('healthy')
        ->and($dscr['operating_cash_flow'])->toBe('1500.00')
        ->and($dscr['debt_service'])->toBe(['principal' => '1000.00', 'interest' => '100.00', 'total' => '1100.00'])
        ->and(array_column($dscr['lines'], 'kind'))->toContain('principal', 'interest', 'operating_inflow');

    lpManualItem($this, 'outflow', 'payable', '700.00', 15);

    $weaker = $this->getJson('/api/v1/liquidity-planning/overview')->json('data.indicators.baseline.dscr');

    expect($weaker['value'])->toBe('0.73')->and($weaker['status'])->toBe('critical');
});

it('judges the capital against the jurisdiction', function (string $jurisdiction, array $settings, string $status): void {
    $this->putJson('/api/v1/liquidity-planning/settings', lpSettingsPayload(['jurisdiction' => $jurisdiction] + $settings))->assertOk();

    $capital = $this->getJson('/api/v1/liquidity-planning/overview')->assertOk()->json('data.indicators.baseline.capital');

    expect($capital['status'])->toBe($status)->and($capital['regime'])->toBe($jurisdiction === 'CH' ? 'CH_CO_725' : 'IT_CC_2446_2447');
})->with([
    'CH covered' => ['CH', ['share_capital' => '100000', 'net_equity_override' => '120000'], 'ok'],
    'CH half capital lost' => ['CH', ['share_capital' => '100000', 'net_equity_override' => '40000'], 'half_capital_lost'],
    'CH over-indebted' => ['CH', ['share_capital' => '100000', 'net_equity_override' => '-5'], 'over_indebted'],
    'IT loss over a third' => ['IT', ['share_capital' => '100000', 'legal_minimum_capital' => '10000', 'net_equity_override' => '60000'], 'watch'],
    'IT loss over a half' => ['IT', ['share_capital' => '100000', 'legal_minimum_capital' => '10000', 'net_equity_override' => '40000'], 'half_capital_lost'],
    'IT below the legal minimum' => ['IT', ['share_capital' => '100000', 'legal_minimum_capital' => '10000', 'net_equity_override' => '5000'], 'below_legal_minimum'],
]);

it('computes the PFN and the PFN / EBITDA ratio', function (): void {
    lpBank($this, '20000.00');
    $this->putJson('/api/v1/liquidity-planning/settings', lpSettingsPayload(['ebitda_override' => '10000']))->assertOk();

    $pfn = $this->getJson('/api/v1/liquidity-planning/overview')->assertOk()->json('data.indicators.baseline.pfn');

    expect($pfn['cash'])->toBe('20000.00')->and($pfn['pfn'])->toBe('-20000.00')->and($pfn['pfn_ebitda'])->toBe('-2.00')->and($pfn['status'])->toBe('ok');
});

it('does not leak the records of another company', function (): void {
    treasuryInTenant($this->otherTenant, fn () => lpManualItemFor($this->otherTenant));
    treasuryBank($this->otherTenant, 'IT60X0542811101000000999999', null, ['current_balance' => '99999.00']);

    $data = $this->getJson('/api/v1/liquidity-planning/overview')->assertOk()->json('data');

    expect($data['opening_cash'])->toBe('0.00')->and($data['baseline']['daily'][5]['inflows'])->toBe('0.00');
});
