<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Treasury\Currency\Http\Requests\StoreManualRateRequest;
use Modules\Treasury\Currency\Http\Requests\UpdateCurrencySettingsRequest;

require_once __DIR__.'/CurrencySupport.php';

uses(RefreshDatabase::class);

/**
 * The currency settings of a company: the functional currency from the company (Mod 001) always active, the active currencies,
 * the alert threshold, the treatment of unrealised gains, the VAT rate method (Swiss rules: kept one tax period), and the 409 of
 * deactivating a currency that still carries open items or balances.
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00'));
    fxSetUp($this);
});

function fxSettingsPayload(array $overrides = []): array
{
    return array_merge([
        'active_currencies' => ['CHF', 'EUR', 'GBP', 'USD'],
        'alert_threshold_percent' => '2.00',
        'unrealized_gain_treatment' => 'recognise',
    ], $overrides);
}

test('an Italian company starts with the euro, the default currencies, ECB rates for VAT and recognised gains', function (): void {
    $settings = $this->getJson('/api/v1/settings/currencies')->assertOk()->json('data');

    expect($settings['functional_currency'])->toBe('EUR')
        ->and($settings['active_currencies'])->toBe(['CHF', 'EUR', 'GBP', 'USD'])
        ->and($settings['alert_threshold_percent'])->toBe('2.00')
        ->and($settings['unrealized_gain_treatment'])->toBe('recognise')
        ->and($settings['vat_rate_method'])->toBe('ecb')
        ->and($settings['revaluation_accounts'])->toBe(['unrealized_gain' => null, 'unrealized_loss' => null, 'unrealized_gain_provision' => null]);
});

test('a Swiss company starts with the franc, ESTV monthly averages for VAT and the gains in a provision (CO Art. 960a)', function (): void {
    treasuryActor($this, 'accountant', $this->swiss);

    $settings = $this->getJson('/api/v1/settings/currencies')->assertOk()->json('data');

    expect($settings['functional_currency'])->toBe('CHF')
        ->and($settings['unrealized_gain_treatment'])->toBe('provision')
        ->and($settings['vat_rate_method'])->toBe('estv_monthly');
});

test('the settings are saved and the functional currency stays active even when left out', function (): void {
    $account = treasuryAccount($this->tenant, '4810', 'Exchange gains');

    $saved = $this->putJson('/api/v1/settings/currencies', [
        'active_currencies' => ['usd', 'GBP'],
        'alert_threshold_percent' => '3.5',
        'unrealized_gain_treatment' => 'provision',
        'vat_rate_method' => 'daily',
        'revaluation_accounts' => ['unrealized_gain' => (string) $account->id],
    ])->assertOk()->json('data');

    expect($saved['active_currencies'])->toBe(['EUR', 'GBP', 'USD'])
        ->and($saved['alert_threshold_percent'])->toBe('3.50')
        ->and($saved['unrealized_gain_treatment'])->toBe('provision')
        ->and($saved['vat_rate_method'])->toBe('daily')
        ->and($saved['revaluation_accounts']['unrealized_gain'])->toBe((string) $account->id)
        ->and($this->getJson('/api/v1/settings/currencies')->json('data'))->toBe($saved);

    // Another company is not touched.
    treasuryActor($this, 'accountant', $this->otherTenant);

    expect($this->getJson('/api/v1/settings/currencies')->json('data.active_currencies'))->toBe(['CHF', 'EUR', 'GBP', 'USD']);
});

test('the alert can be switched off', function (): void {
    $this->putJson('/api/v1/settings/currencies', fxSettingsPayload(['alert_threshold_percent' => null]))->assertOk()->assertJsonPath('data.alert_threshold_percent', null);
});

test('a currency with a balance cannot be deactivated, once it is empty it can', function (): void {
    $bank = treasuryBank($this->tenant, 'IT60X0542811101000000123456', null, ['currency' => 'USD', 'current_balance' => '500.00']);

    $response = $this->putJson('/api/v1/settings/currencies', fxSettingsPayload(['active_currencies' => ['EUR', 'CHF', 'GBP']]))->assertStatus(409);

    expect($response->json('code'))->toBe('currency_in_use')
        ->and($response->json('message'))->toContain('USD')
        ->and($response->json('errors.active_currencies'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/settings/currencies')->json('data.active_currencies'))->toContain('USD');

    $bank->forceFill(['current_balance' => '0.00'])->save();

    $this->putJson('/api/v1/settings/currencies', fxSettingsPayload(['active_currencies' => ['EUR', 'CHF', 'GBP']]))->assertOk()->assertJsonPath('data.active_currencies', ['CHF', 'EUR', 'GBP']);
});

test('a Swiss company keeps its VAT rate method for at least one tax period', function (): void {
    treasuryActor($this, 'accountant', $this->swiss);

    $this->putJson('/api/v1/settings/currencies', fxSettingsPayload(['vat_rate_method' => 'daily']))->assertOk()->assertJsonPath('data.vat_rate_method', 'daily');
    $this->putJson('/api/v1/settings/currencies', fxSettingsPayload(['vat_rate_method' => 'daily', 'alert_threshold_percent' => '4']))->assertOk();

    $this->putJson('/api/v1/settings/currencies', fxSettingsPayload(['vat_rate_method' => 'estv_monthly']))->assertStatus(409)->assertJsonPath('code', 'vat_method_locked');

    $this->travelTo(CarbonImmutable::parse('2027-03-30 10:00:00'));

    $this->putJson('/api/v1/settings/currencies', fxSettingsPayload(['vat_rate_method' => 'estv_monthly']))->assertOk()->assertJsonPath('data.vat_rate_method', 'estv_monthly');
});

test('a VAT rate method of another country is refused', function (): void {
    $this->putJson('/api/v1/settings/currencies', fxSettingsPayload(['vat_rate_method' => 'estv_monthly']))->assertStatus(422)->assertJsonPath('code', 'vat_method_not_allowed');

    treasuryActor($this, 'accountant', $this->swiss);

    $this->putJson('/api/v1/settings/currencies', fxSettingsPayload(['vat_rate_method' => 'ecb']))->assertStatus(422)->assertJsonValidationErrors(['vat_rate_method']);
});

test('a revaluation account that is not in the chart of accounts of the company is refused', function (): void {
    $this->putJson('/api/v1/settings/currencies', fxSettingsPayload(['revaluation_accounts' => ['unrealized_loss' => (string) Str::uuid()]]))
        ->assertStatus(422)->assertJsonPath('code', 'account_not_found');

    $foreign = treasuryAccount($this->otherTenant, '4810', 'Their gains');

    $this->putJson('/api/v1/settings/currencies', fxSettingsPayload(['revaluation_accounts' => ['unrealized_gain' => (string) $foreign->id]]))
        ->assertStatus(422)->assertJsonPath('code', 'account_not_found');
});

test('the settings are validated', function (): void {
    $this->putJson('/api/v1/settings/currencies', [])->assertStatus(422)->assertJsonValidationErrors(['active_currencies', 'unrealized_gain_treatment']);
    $this->putJson('/api/v1/settings/currencies', fxSettingsPayload(['active_currencies' => ['EURO']]))->assertStatus(422)->assertJsonValidationErrors(['active_currencies.0']);
    $this->putJson('/api/v1/settings/currencies', fxSettingsPayload(['alert_threshold_percent' => 'abc']))->assertStatus(422)->assertJsonValidationErrors(['alert_threshold_percent']);
    $this->putJson('/api/v1/settings/currencies', fxSettingsPayload(['alert_threshold_percent' => '1.234']))->assertStatus(422)->assertJsonValidationErrors(['alert_threshold_percent']);
    $this->putJson('/api/v1/settings/currencies', fxSettingsPayload(['unrealized_gain_treatment' => 'ignore']))->assertStatus(422)->assertJsonValidationErrors(['unrealized_gain_treatment']);
    $this->putJson('/api/v1/settings/currencies', fxSettingsPayload(['revaluation_accounts' => ['unrealized_gain' => 'not-a-uuid']]))->assertStatus(422);
});

test('a member reads the settings but cannot change them', function (): void {
    treasuryActAs($this, $this->member);

    $this->getJson('/api/v1/settings/currencies')->assertOk();
    $this->putJson('/api/v1/settings/currencies', fxSettingsPayload())->assertForbidden();
});

test('the form requests of the engine reject what the rules of the domain refuse', function (): void {
    $rate = fn (array $input): bool => Validator::make($input + ['base' => 'EUR', 'quote' => 'CHF', 'valid_on' => '2026-11-20'], (new StoreManualRateRequest)->rules())->passes();

    expect($rate(['rate' => '0.941200']))->toBeTrue()
        ->and($rate(['rate' => '1']))->toBeTrue()
        ->and($rate(['rate' => '0.0000001']))->toBeFalse()
        ->and($rate(['rate' => '0.000000']))->toBeFalse()
        ->and($rate(['rate' => '-1']))->toBeFalse()
        ->and($rate(['rate' => '1e3']))->toBeFalse()
        ->and($rate(['rate' => '0.9', 'rate_type' => 'contract']))->toBeFalse()
        ->and($rate(['rate' => '0.9', 'rate_type' => 'contract', 'reason' => 'Annual contract']))->toBeTrue()
        ->and($rate(['rate' => '0.9', 'rate_type' => 'vat_estv_monthly']))->toBeTrue();

    $settings = fn (array $input): bool => Validator::make($input, (new UpdateCurrencySettingsRequest)->rules())->passes();

    expect($settings(fxSettingsPayload()))->toBeTrue()
        ->and($settings(fxSettingsPayload(['alert_threshold_percent' => '12.5'])))->toBeTrue()
        ->and($settings(fxSettingsPayload(['active_currencies' => []])))->toBeFalse()
        ->and($settings(fxSettingsPayload(['vat_rate_method' => 'bogus'])))->toBeFalse();
});
