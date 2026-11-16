<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Invoicing\Tax\TenantFiscalSettings;
use App\Services\ItalianSdi\FatturaPaBuilderService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'Forfettario Verdi',
        'legal_name' => 'Giulia Verdi',
        'vat_number' => '00811720580',
        'fiscal_code' => '00811720580',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    $this->user = User::create(['name' => 'Giulia Verdi', 'email' => 'giulia@verdi.example', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
});

function fiscalSettingsApi()
{
    Sanctum::actingAs(test()->user);

    return test()->withHeader('X-Tenant-Id', test()->tenant->id);
}

test('fiscal preferences are validated, stored and mirrored to the legacy regime_fiscale key', function (): void {
    $payload = [
        'regime_fiscale' => 'RF19',
        'default_fund' => ['type' => 'TC22', 'percent' => 4, 'vat_rate' => null, 'natura' => 'N2.2', 'withholding_applies' => false],
        'default_withholding' => null,
        'stamp_duty_charge_mode' => 'issuer',
        'enasarco_enabled' => false,
    ];

    fiscalSettingsApi()->putJson('/api/v1/settings/preferences/fiscal', $payload)
        ->assertOk()
        ->assertJsonPath('data.regime_fiscale', 'RF19')
        ->assertJsonPath('data.default_fund.type', 'TC22')
        ->assertJsonPath('data.stamp_duty_charge_mode', 'issuer');

    $fiscal = fiscalSettingsApi()->getJson('/api/v1/settings/preferences')->assertOk()->json('data.fiscal');

    expect($fiscal)->toHaveKeys(['regime_fiscale', 'default_fund', 'stamp_duty_charge_mode', 'enasarco_enabled'])
        ->and($fiscal)->not->toHaveKey('default_withholding')
        ->and($this->tenant->refresh()->settings['regime_fiscale'])->toBe('RF19');

    $settings = TenantFiscalSettings::fromTenant($this->tenant);

    expect($settings->regimeFiscale)->toBe('RF19')
        ->and($settings->isForfettario())->toBeTrue()
        ->and($settings->stampDutyChargedToCustomer())->toBeFalse()
        ->and($settings->defaultFund()['type'])->toBe('TC22')
        ->and($settings->enasarcoEnabled())->toBeFalse();

    fiscalSettingsApi()->putJson('/api/v1/settings/preferences/fiscal', ['regime_fiscale' => null])->assertOk();

    expect($this->tenant->refresh()->settings)->not->toHaveKey('regime_fiscale')
        ->and(TenantFiscalSettings::fromTenant($this->tenant)->regimeFiscale)->toBe('RF01');
});

test('invalid fiscal preferences are rejected', function (array $payload, string $field): void {
    fiscalSettingsApi()->putJson('/api/v1/settings/preferences/fiscal', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'unknown regime' => [['regime_fiscale' => 'RF99'], 'regime_fiscale'],
    'unknown fund type' => [['default_fund' => ['type' => 'TC99', 'percent' => 4]], 'default_fund.type'],
    'fund without percent' => [['default_fund' => ['type' => 'TC22']], 'default_fund.percent'],
    'unknown withholding reason' => [['default_withholding' => ['type' => 'RT01', 'reason' => 'ZZ', 'rate' => 20]], 'default_withholding.reason'],
    'withholding rate above 100' => [['default_withholding' => ['type' => 'RT01', 'reason' => 'A', 'rate' => 120]], 'default_withholding.rate'],
    'unknown stamp duty payer' => [['stamp_duty_charge_mode' => 'nobody'], 'stamp_duty_charge_mode'],
]);

test('the legacy settings.regime_fiscale key is still honoured and the preference wins over it', function (): void {
    $this->tenant->update(['settings' => ['regime_fiscale' => 'RF19']]);
    expect(TenantFiscalSettings::fromTenant($this->tenant)->regimeFiscale)->toBe('RF19');

    $this->tenant->update(['settings' => ['regime_fiscale' => 'RF19', 'preferences' => ['fiscal' => ['regime_fiscale' => 'RF01']]]]);
    expect(TenantFiscalSettings::fromTenant($this->tenant)->regimeFiscale)->toBe('RF01');
});

test('the FatturaPA RegimeFiscale follows the fiscal preference', function (): void {
    fiscalSettingsApi()->putJson('/api/v1/settings/preferences/fiscal', ['regime_fiscale' => 'RF19'])->assertOk();

    app(TenantContext::class)->setTenant($this->tenant->refresh());
    $contact = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Cliente', 'country' => 'IT', 'sdi_code' => '0000000']);
    $invoice = Invoice::create([
        'tenant_id' => $this->tenant->id, 'contact_id' => $contact->id, 'invoice_type' => 'sdi_electronic', 'invoice_number' => 'F-1',
        'date' => '2026-03-01', 'currency' => 'EUR', 'jurisdiction' => 'IT', 'total_net' => 100, 'total_gross' => 100,
    ]);

    $xml = (new FatturaPaBuilderService)->buildFatturaPaXml($invoice->load(['tenant', 'contact', 'items']));

    expect($xml)->toContain('<RegimeFiscale>RF19</RegimeFiscale>');
});

test('fiscal preferences of one tenant never leak to another', function (): void {
    $other = Tenant::create(['name' => 'Other', 'legal_name' => 'Other SRL', 'vat_number' => '01234567890', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->user->tenants()->attach($other->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);

    fiscalSettingsApi()->putJson('/api/v1/settings/preferences/fiscal', ['regime_fiscale' => 'RF19'])->assertOk();

    Sanctum::actingAs($this->user);
    $this->withHeader('X-Tenant-Id', $other->id)->getJson('/api/v1/settings/preferences')
        ->assertOk()
        ->assertJsonPath('data.fiscal', []);
});
