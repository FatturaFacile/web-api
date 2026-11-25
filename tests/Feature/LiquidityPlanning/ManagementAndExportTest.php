<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/LiquiditySupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    lpSetUp($this);
});

it('requires authentication', function (): void {
    $this->refreshApplication();

    $this->withHeader('X-Tenant-Id', (string) $this->tenant->id)->getJson('/api/v1/liquidity-planning/overview')->assertUnauthorized();
});

it('lets the finance roles read and closes the module to the other members', function (string $role, int $status): void {
    treasuryActor($this, $role);

    foreach (['overview', 'settings', 'manual-items', 'scenarios', 'scenarios/presets', 'alerts', 'report'] as $path) {
        $this->getJson("/api/v1/liquidity-planning/{$path}")->assertStatus($status);
    }
})->with([
    'owner' => ['owner', 200],
    'admin' => ['admin', 200],
    'accountant' => ['accountant', 200],
    'read-only accountant' => ['accountant_readonly', 200],
    'sales' => ['sales', 403],
    'plain member' => ['member', 403],
]);

it('keeps the changes to the roles that write', function (): void {
    treasuryActor($this, 'accountant_readonly');

    $this->putJson('/api/v1/liquidity-planning/settings', lpSettingsPayload())->assertForbidden();
    $this->postJson('/api/v1/liquidity-planning/alerts/evaluate')->assertForbidden();
    $this->postJson('/api/v1/liquidity-planning/scenarios', ['name' => 'X', 'levers' => []])->assertForbidden();
});

it('saves and reads the settings and rejects an inconsistent threshold', function (): void {
    $this->putJson('/api/v1/liquidity-planning/settings', lpSettingsPayload(['jurisdiction' => 'CH', 'share_capital' => '200000', 'notify_emails' => ['a@example.com', 'a@example.com']]))
        ->assertOk()->assertJsonPath('data.jurisdiction', 'CH')->assertJsonPath('data.notify_emails', ['a@example.com']);

    $this->getJson('/api/v1/liquidity-planning/settings')->assertOk()->assertJsonPath('data.share_capital', '200000.00');
    $this->putJson('/api/v1/liquidity-planning/settings', lpSettingsPayload(['dscr_critical_threshold' => '1.50', 'dscr_warning_threshold' => '1.20']))->assertUnprocessable();
});

it('defaults the jurisdiction of the settings to the country of the company', function (): void {
    $this->getJson('/api/v1/liquidity-planning/settings')->assertOk()->assertJsonPath('data.jurisdiction', 'IT')->assertJsonPath('data.dscr_warning_threshold', '1.20');
});

it('manages the manual forecast items', function (): void {
    $payload = ['label' => 'Rata leasing', 'tier' => 'probable', 'category' => 'lease', 'direction' => 'outflow', 'amount' => '800.00', 'currency' => 'eur', 'first_date' => now()->addDays(3)->toDateString(), 'frequency' => 'monthly'];

    $id = $this->postJson('/api/v1/liquidity-planning/manual-items', $payload)->assertCreated()->assertJsonPath('data.currency', 'EUR')->json('data.id');
    $this->putJson("/api/v1/liquidity-planning/manual-items/{$id}", array_merge($payload, ['amount' => '900.00']))->assertOk()->assertJsonPath('data.amount', '900.00');
    $this->getJson('/api/v1/liquidity-planning/manual-items')->assertOk()->assertJsonCount(1, 'data');

    $monthly = $this->getJson('/api/v1/liquidity-planning/overview')->json('data.horizons.3.baseline.tiers.probable.outflows');

    expect((float) $monthly)->toBeGreaterThanOrEqual(5400.0);

    $this->deleteJson("/api/v1/liquidity-planning/manual-items/{$id}")->assertOk();
    $this->getJson('/api/v1/liquidity-planning/manual-items')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson('/api/v1/liquidity-planning/manual-items', array_merge($payload, ['tier' => 'wild']))->assertUnprocessable();
});

it('does not let a company touch the items and the scenarios of another one', function (): void {
    treasuryInTenant($this->otherTenant, fn () => lpManualItemFor($this->otherTenant));
    $scenarioId = $this->postJson('/api/v1/liquidity-planning/scenarios', ['name' => 'Mio', 'levers' => ['collection_delay_days' => 10]])->assertCreated()->json('data.id');

    treasuryActor($this, 'accountant', $this->otherTenant);

    $this->getJson('/api/v1/liquidity-planning/scenarios')->assertOk()->assertJsonCount(0, 'data');
    $this->deleteJson("/api/v1/liquidity-planning/scenarios/{$scenarioId}")->assertNotFound();
    $this->getJson("/api/v1/liquidity-planning/overview?scenario_id={$scenarioId}")->assertUnprocessable();
});

it('saves a what-if scenario and applies it by id', function (): void {
    lpBank($this, '10000.00');
    lpInvoice($this, 'FT-1', '3000.00', 10);

    $presets = $this->getJson('/api/v1/liquidity-planning/scenarios/presets')->assertOk()->json('data');
    expect(array_column($presets, 'code'))->toBe(['collection_delay_20', 'raw_material_spike', 'capex_acceleration']);

    $scenario = $this->postJson('/api/v1/liquidity-planning/scenarios', ['name' => 'Ritardo 60', 'description' => 'Incassi a +60', 'levers' => ['collection_delay_days' => 60, 'inflow_change_percent' => '-10']])
        ->assertCreated()->assertJsonPath('data.levers.collection_delay_days', 60)->json('data');

    $overview = $this->getJson("/api/v1/liquidity-planning/overview?scenario_id={$scenario['id']}")->assertOk()->json('data');

    expect($overview['scenario_info']['name'])->toBe('Ritardo 60')
        ->and($overview['horizons'][0]['scenario']['closing_balance'])->toBe('10000.00')
        ->and($overview['horizons'][3]['scenario']['closing_balance'])->toBe('12700.00');

    $this->putJson("/api/v1/liquidity-planning/scenarios/{$scenario['id']}", ['name' => 'Ritardo 90', 'levers' => ['collection_delay_days' => 90]])->assertOk()->assertJsonPath('data.name', 'Ritardo 90');
    $this->deleteJson("/api/v1/liquidity-planning/scenarios/{$scenario['id']}")->assertOk();
    $this->getJson('/api/v1/liquidity-planning/scenarios')->assertOk()->assertJsonCount(0, 'data');
});

it('rejects levers out of range', function (): void {
    $this->getJson('/api/v1/liquidity-planning/overview?levers[collection_delay_days]=500')->assertUnprocessable();
    $this->postJson('/api/v1/liquidity-planning/scenarios', ['name' => 'X', 'levers' => ['raw_material_increase_percent' => 900]])->assertUnprocessable();
});

it('builds the board report with the EBA sections', function (): void {
    lpBank($this, '5000.00');

    $report = $this->getJson('/api/v1/liquidity-planning/report')->assertOk()->json('data');

    expect(array_column($report['eba']['sections'], 'key'))->toBe(['cash_flow_sustainability', 'debt_service_capacity', 'capital_adequacy', 'leverage', 'stress_resilience', 'governance_oversight'])
        ->and($report['company']['name'])->toBe('Tesoreria Alfa Srl')
        ->and($report['overview']['opening_cash'])->toBe('5000.00')
        ->and($report['eba']['sections'][0]['status'])->toBe('ok');
});

it('exports the forecast to a workbook and the report to a PDF', function (): void {
    lpBank($this, '5000.00');
    lpInvoice($this, 'FT-1', '1000.00', 10);

    $xlsx = $this->get('/api/v1/liquidity-planning/export/xlsx?locale=it&levers[collection_delay_days]=30')->assertOk();
    $pdf = $this->get('/api/v1/liquidity-planning/report/pdf?locale=de')->assertOk();

    expect($xlsx->headers->get('Content-Type'))->toContain('spreadsheetml')
        ->and(str_starts_with((string) $xlsx->getContent(), 'PK'))->toBeTrue()
        ->and($pdf->headers->get('Content-Type'))->toContain('application/pdf')
        ->and(str_starts_with((string) $pdf->getContent(), '%PDF'))->toBeTrue();
});
