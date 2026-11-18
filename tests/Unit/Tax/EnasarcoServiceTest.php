<?php

declare(strict_types=1);

use App\Exceptions\FiscalRuleException;
use App\Models\Contact;
use App\Models\EnasarcoAnnualLimit;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Invoicing\Tax\EnasarcoService;
use App\Services\Invoicing\Tax\FiscalInput;
use App\Services\Invoicing\Tax\FiscalLine;
use App\Services\Invoicing\Tax\ItalianFiscalCalculator;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'Agente Rossi',
        'legal_name' => 'Mario Rossi Agente di Commercio',
        'vat_number' => '00811720580',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->principal = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Mandante SpA', 'country' => 'IT']);
    $this->service = app(EnasarcoService::class);
});

function enasarcoInvoice(string $number, string $date, ?string $commissions, ?string $amount = null, string $type = 'standard'): Invoice
{
    return Invoice::create([
        'tenant_id' => test()->tenant->id,
        'contact_id' => test()->principal->id,
        'invoice_type' => $type,
        'invoice_number' => $number,
        'date' => $date,
        'currency' => 'EUR',
        'jurisdiction' => 'IT',
        'total_net' => $commissions ?? '0',
        'enasarco_commissions' => $commissions,
        'enasarco_amount' => $amount,
    ]);
}

test('a tenant row overrides the shared row, which overrides config', function (): void {
    // Shared rows are created outside a tenant context as global records.
    app(TenantContext::class)->clear();
    EnasarcoAnnualLimit::createGlobal(['year' => 2026, 'rate' => '5.00', 'annual_minimum' => '100.00', 'annual_maximum_commission' => '50000.00']);
    app(TenantContext::class)->setTenant($this->tenant);

    expect($this->service->limitsFor(2026))->toBe(['rate' => '5.00', 'annual_minimum' => '100.00', 'annual_maximum_commission' => '50000.00']);

    EnasarcoAnnualLimit::create(['tenant_id' => $this->tenant->id, 'year' => 2026, 'rate' => '4.00', 'annual_minimum' => '0.00', 'annual_maximum_commission' => '100000.00']);

    expect($this->service->limitsFor(2026)['rate'])->toBe('4.00')
        ->and($this->service->limitsFor(2025))->toBe([
            'rate' => config('italian_tax.enasarco.years.2025.rate'),
            'annual_minimum' => config('italian_tax.enasarco.years.2025.annual_minimum'),
            'annual_maximum_commission' => config('italian_tax.enasarco.years.2025.annual_maximum_commission'),
        ]);
});

test('a year without a configured rate is rejected with enasarco_rate_not_configured', function (): void {
    try {
        $this->service->limitsFor(1999);
        $this->fail('Expected FiscalRuleException.');
    } catch (FiscalRuleException $exception) {
        expect($exception->errorCode)->toBe('enasarco_rate_not_configured')
            ->and($exception->field)->toBe('enasarco')
            ->and($exception->render()->getStatusCode())->toBe(422)
            ->and($exception->render()->getData(true)['code'])->toBe('enasarco_rate_not_configured');
    }
});

test('year-to-date commissions per principal feed the annual ceiling (99000 + 3000 on 100000 -> base 1000, 40.00)', function (): void {
    EnasarcoAnnualLimit::create(['tenant_id' => $this->tenant->id, 'year' => 2026, 'rate' => '4.00', 'annual_minimum' => '0.00', 'annual_maximum_commission' => '100000.00']);
    enasarcoInvoice('AG-1', '2026-02-10', '60000.00', '2400.00');
    enasarcoInvoice('AG-2', '2026-06-10', '39000.00', '1560.00');
    enasarcoInvoice('AG-OLD', '2025-12-31', '50000.00', '2000.00');

    $settings = $this->service->settingsFor($this->principal->id, 2026);

    expect($settings->ytdCommissions)->toBe('99000.00')
        ->and($settings->rate)->toBe('4.00')
        ->and($settings->annualMaximumCommission)->toBe('100000.00');

    $result = (new ItalianFiscalCalculator)->calculate(new FiscalInput(
        lines: [new FiscalLine('1', '3000.00', '22')],
        enasarco: $settings,
    ));

    expect($result->enasarcoTaxable)->toBe(100000)
        ->and($result->enasarco)->toBe(4000);
});

test('credit notes reduce the year-to-date commissions and the status reports the minimum shortfall', function (): void {
    EnasarcoAnnualLimit::create(['tenant_id' => $this->tenant->id, 'year' => 2026, 'rate' => '4.00', 'annual_minimum' => '500.00', 'annual_maximum_commission' => '100000.00']);
    enasarcoInvoice('AG-1', '2026-02-10', '10000.00', '400.00');
    enasarcoInvoice('NC-1', '2026-03-10', '2000.00', '80.00', 'credit_note');

    expect($this->service->status($this->principal->id, 2026))->toBe([
        'contact_id' => $this->principal->id,
        'year' => 2026,
        'rate' => '4.00',
        'ytd_commissions' => '8000.00',
        'annual_maximum' => '100000.00',
        'remaining_cap' => '92000.00',
        'annual_minimum' => '500.00',
        'ytd_contributions' => '320.00',
        'shortfall' => '180.00',
    ]);
});

test('commissions of other tenants are never counted', function (): void {
    EnasarcoAnnualLimit::create(['tenant_id' => $this->tenant->id, 'year' => 2026, 'rate' => '4.00', 'annual_minimum' => '0.00', 'annual_maximum_commission' => '100000.00']);
    enasarcoInvoice('AG-1', '2026-02-10', '1000.00', '40.00');

    $other = Tenant::create(['name' => 'Other', 'legal_name' => 'Other SRL', 'vat_number' => '01234567890', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($other);

    expect($this->service->ytdCommissionsCents($this->principal->id, 2026))->toBe(0);
});
