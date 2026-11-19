<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\EnasarcoAnnualLimit;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-03-15 10:00:00');

    $this->tenant = Tenant::create([
        'name' => 'Studio Bianchi',
        'legal_name' => 'Studio Bianchi',
        'vat_number' => '00811720580',
        'fiscal_code' => '00811720580',
        'country' => 'IT',
        'default_currency' => 'EUR',
        'iban' => 'IT60X0542811101000000123456',
        'settings' => [
            'street' => 'Via Roma 1',
            'postal_code' => '00100',
            'city' => 'Roma',
        ],
    ]);

    $this->user = User::create(['name' => 'Laura Bianchi', 'email' => 'laura@studio.example', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);

    app(TenantContext::class)->setTenant($this->tenant);
    fiscalCalendar($this->tenant, 2026);

    $this->customer = Contact::create([
        'tenant_id' => $this->tenant->id,
        'company_name' => 'Cliente SRL',
        'country' => 'IT',
        'vat_number' => '01234567890',
        'sdi_code' => '0000000',
        'pec' => 'cliente@pec.example.com',
        'address' => 'Via Milano 10',
        'postal_code' => '20121',
        'city' => 'Milano',
    ]);
});

function fiscalFlowApi()
{
    Sanctum::actingAs(test()->user);

    return test()->withHeader('X-Tenant-Id', test()->tenant->id);
}

test('fiscal preview returns cassa, VAT summary, ritenuta, ENASARCO, stamp duty and due amount as decimal strings', function (): void {
    fiscalFlowApi()->postJson('/api/v1/tax/preview', [
        'items' => [['description' => 'Consulenza', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 22]],
        'fund' => ['type' => 'TC22', 'percent' => 4],
        'withholding' => ['type' => 'RT01', 'reason' => 'A', 'rate' => 20],
    ])->assertOk()
        ->assertJsonPath('data.totals', [
            'net' => '1000.00',
            'fund' => '40.00',
            'taxable_vat' => '1040.00',
            'vat' => '228.80',
            'stamp_duty' => '0.00',
            'gross' => '1268.80',
            'withholding' => '200.00',
            'enasarco' => '0.00',
            'split_payment_vat' => '0.00',
            'due' => '1068.80',
        ])
        ->assertJsonPath('data.vat_summary', [['rate' => '22.00', 'natura' => null, 'taxable' => '1040.00', 'tax' => '228.80', 'payability' => 'I']])
        ->assertJsonPath('data.stamp_duty.applied', false);

    expect(Invoice::count())->toBe(0);

    fiscalFlowApi()->postJson('/api/v1/tax/preview', ['items' => [['quantity' => 1, 'unit_price' => 10, 'tax_rate' => 0, 'natura' => 'X1']]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('items.0.natura');
});

test('fiscal preview rejects incompatible split payment or forfettario combinations with error codes', function (): void {
    fiscalFlowApi()->postJson('/api/v1/tax/preview', [
        'split_payment' => true,
        'reverse_charge' => true,
        'items' => [['description' => 'Servizio', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 22]],
    ])->assertUnprocessable()
        ->assertJsonPath('code', 'split_payment_incompatible')
        ->assertJsonValidationErrors('split_payment');

    $this->tenant->update(['settings' => array_merge($this->tenant->settings ?? [], ['regime_fiscale' => 'RF19'])]);
    app(TenantContext::class)->setTenant($this->tenant->refresh());

    fiscalFlowApi()->postJson('/api/v1/tax/preview', [
        'withholding' => ['type' => 'RT01', 'reason' => 'A', 'rate' => 20],
        'items' => [['description' => 'Consulenza', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0]],
    ])->assertUnprocessable()
        ->assertJsonPath('code', 'forfettario_incompatible')
        ->assertJsonValidationErrors('withholding');
});

test('enasarco status endpoint reports year-to-date commissions, remaining cap and shortfall', function (): void {
    EnasarcoAnnualLimit::create([
        'tenant_id' => $this->tenant->id,
        'year' => 2026,
        'rate' => '4.00',
        'annual_minimum' => '500.00',
        'annual_maximum_commission' => '100000.00',
    ]);

    Invoice::create([
        'tenant_id' => $this->tenant->id,
        'contact_id' => $this->customer->id,
        'invoice_type' => 'sdi_electronic',
        'invoice_number' => 'AG-PREV',
        'date' => '2026-02-01',
        'currency' => 'EUR',
        'jurisdiction' => 'IT',
        'total_net' => '99000.00',
        'enasarco_commissions' => '99000.00',
        'enasarco_amount' => '3960.00',
    ]);

    fiscalFlowApi()->getJson("/api/v1/tax/enasarco/status?contact_id={$this->customer->id}&year=2026")
        ->assertOk()
        ->assertJsonPath('data.ytd_commissions', '99000.00')
        ->assertJsonPath('data.remaining_cap', '1000.00')
        ->assertJsonPath('data.shortfall', '0.00');

    fiscalFlowApi()->getJson("/api/v1/tax/enasarco/status?contact_id={$this->customer->id}&year=2001")
        ->assertUnprocessable()
        ->assertJsonPath('code', 'enasarco_rate_not_configured');
});

test('nested fiscal payload options on invoice creation normalize cleanly to the single writer', function (): void {
    $response = fiscalFlowApi()->postJson('/api/v1/sales/invoices', [
        'contact_id' => $this->customer->id,
        'invoice_type' => 'sdi_electronic',
        'date' => '2026-03-15',
        'due_date' => '2026-04-14',
        'currency' => 'EUR',
        'jurisdiction' => 'IT',
        'fund' => ['type' => 'TC22', 'percent' => 4, 'vat_rate' => 22],
        'withholding' => ['type' => 'RT01', 'reason' => 'A', 'rate' => 20],
        'items' => [['description' => 'Consulenza professionale', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 22]],
    ])->assertCreated();

    expect((float) $response->json('data.total_net'))->toBe(1000.0)
        ->and($response->json('data.fund_enabled'))->toBeTrue()
        ->and($response->json('data.fund_type'))->toBe('TC22')
        ->and((float) $response->json('data.fund_amount'))->toBe(40.0)
        ->and($response->json('data.withholding_tax_enabled'))->toBeTrue()
        ->and($response->json('data.withholding_tax_type'))->toBe('RT01')
        ->and((float) $response->json('data.withholding_tax_amount'))->toBe(200.0);
});
