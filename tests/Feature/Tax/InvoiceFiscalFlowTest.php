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
    ]);

    $this->user = User::create(['name' => 'Laura Bianchi', 'email' => 'laura@studio.example', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);

    app(TenantContext::class)->setTenant($this->tenant);

    $this->customer = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Cliente SRL', 'country' => 'IT', 'vat_number' => '01234567890', 'sdi_code' => '0000000', 'pec' => 'cliente@pec.example.com']);
    $this->publicAdministration = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Comune di Roma', 'country' => 'IT', 'fiscal_code' => '02438750586', 'sdi_code' => 'UF1234']);
});

function fiscalFlowApi()
{
    Sanctum::actingAs(test()->user);

    return test()->withHeader('X-Tenant-Id', test()->tenant->id);
}

/**
 * @param  array<string, mixed>  $overrides
 * @param  array<int, array<string, mixed>>|null  $items
 * @return array<string, mixed>
 */
function fiscalFlowPayload(array $overrides = [], ?array $items = null): array
{
    return array_merge([
        'contact_id' => test()->customer->id,
        'invoice_type' => 'sdi_electronic',
        'invoice_number' => 'FT-'.Str::random(6),
        'date' => '2026-03-15',
        'due_date' => '2026-04-14',
        'currency' => 'EUR',
        'jurisdiction' => 'IT',
        'items' => $items ?? [['description' => 'Consulenza professionale', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 22]],
    ], $overrides);
}

function fiscalFlowXml(string $invoiceId): DOMXPath
{
    $xml = fiscalFlowApi()->postJson("/api/v1/sales/invoices/{$invoiceId}/generate-sdi-xml")->assertOk()->json('data.xml_preview');

    $document = new DOMDocument;
    $document->loadXML($xml);

    return new DOMXPath($document);
}

function fiscalFlowValue(DOMXPath $xpath, string $query): ?string
{
    $node = $xpath->query($query)?->item(0);

    return $node?->textContent;
}

/**
 * @return list<string>
 */
function fiscalFlowChildren(DOMXPath $xpath, string $query): array
{
    $names = [];

    foreach ($xpath->query($query.'/*') ?: [] as $child) {
        $names[] = $child->nodeName;
    }

    return $names;
}

// ------------------------------------------------------------ Professional: cassa + ritenuta

test('professional invoice persists cassa, ritenuta and amount due, and the XML matches them to the cent', function (): void {
    $response = fiscalFlowApi()->postJson('/api/v1/sales/invoices', fiscalFlowPayload([
        'fund' => ['type' => 'TC22', 'percent' => 4],
        'withholding' => ['type' => 'RT01', 'reason' => 'A', 'rate' => 20],
    ]))->assertCreated();

    $response->assertJsonPath('data.total_net', 1000.0)
        ->assertJsonPath('data.total_tax', 228.8)
        ->assertJsonPath('data.total_gross', 1268.8)
        ->assertJsonPath('data.total_due', '1068.80')
        ->assertJsonPath('data.fund.amount', '40.00')
        ->assertJsonPath('data.fund.vat_rate', '22.00')
        ->assertJsonPath('data.withholding.base', '1000.00')
        ->assertJsonPath('data.withholding.amount', '200.00')
        ->assertJsonPath('data.vat_summary.0.taxable', '1040.00')
        ->assertJsonPath('data.vat_summary.0.tax', '228.80')
        ->assertJsonPath('data.stamp_duty_applied', false);

    $invoice = Invoice::findOrFail($response->json('data.id'));
    expect((string) $invoice->total_due)->toBe('1068.80')
        ->and((string) $invoice->fund_amount)->toBe('40.00')
        ->and((string) $invoice->withholding_tax_amount)->toBe('200.00')
        ->and($invoice->withholding_tax_type)->toBe('RT01');

    $xpath = fiscalFlowXml($invoice->id);
    $doc = '//FatturaElettronicaBody/DatiGenerali/DatiGeneraliDocumento';

    expect(fiscalFlowChildren($xpath, $doc))->toBe(['TipoDocumento', 'Divisa', 'Data', 'Numero', 'DatiRitenuta', 'DatiCassaPrevidenziale', 'ImportoTotaleDocumento'])
        ->and(fiscalFlowChildren($xpath, "{$doc}/DatiRitenuta"))->toBe(['TipoRitenuta', 'ImportoRitenuta', 'AliquotaRitenuta', 'CausalePagamento'])
        ->and(fiscalFlowValue($xpath, "{$doc}/DatiRitenuta/TipoRitenuta"))->toBe('RT01')
        ->and(fiscalFlowValue($xpath, "{$doc}/DatiRitenuta/ImportoRitenuta"))->toBe('200.00')
        ->and(fiscalFlowValue($xpath, "{$doc}/DatiRitenuta/AliquotaRitenuta"))->toBe('20.00')
        ->and(fiscalFlowValue($xpath, "{$doc}/DatiRitenuta/CausalePagamento"))->toBe('A')
        ->and(fiscalFlowChildren($xpath, "{$doc}/DatiCassaPrevidenziale"))->toBe(['TipoCassa', 'AlCassa', 'ImportoContributoCassa', 'ImponibileCassa', 'AliquotaIVA'])
        ->and(fiscalFlowValue($xpath, "{$doc}/DatiCassaPrevidenziale/TipoCassa"))->toBe('TC22')
        ->and(fiscalFlowValue($xpath, "{$doc}/DatiCassaPrevidenziale/AlCassa"))->toBe('4.00')
        ->and(fiscalFlowValue($xpath, "{$doc}/DatiCassaPrevidenziale/ImportoContributoCassa"))->toBe('40.00')
        ->and(fiscalFlowValue($xpath, "{$doc}/DatiCassaPrevidenziale/ImponibileCassa"))->toBe('1000.00')
        ->and(fiscalFlowValue($xpath, "{$doc}/DatiCassaPrevidenziale/AliquotaIVA"))->toBe('22.00')
        ->and(fiscalFlowValue($xpath, "{$doc}/ImportoTotaleDocumento"))->toBe('1268.80')
        ->and(fiscalFlowValue($xpath, '//DettaglioLinee/Ritenuta'))->toBe('SI')
        ->and(fiscalFlowChildren($xpath, '//DatiRiepilogo'))->toBe(['AliquotaIVA', 'ImponibileImporto', 'Imposta', 'EsigibilitaIVA'])
        ->and(fiscalFlowValue($xpath, '//DatiRiepilogo/ImponibileImporto'))->toBe('1040.00')
        ->and(fiscalFlowValue($xpath, '//DatiRiepilogo/Imposta'))->toBe('228.80')
        ->and(fiscalFlowValue($xpath, '//DatiRiepilogo/EsigibilitaIVA'))->toBe('I')
        ->and(fiscalFlowValue($xpath, '//DettaglioPagamento/ImportoPagamento'))->toBe('1068.80');
});

test('ritenuta including the cassa flags the cassa with Ritenuta SI and lowers the amount due', function (): void {
    $response = fiscalFlowApi()->postJson('/api/v1/sales/invoices', fiscalFlowPayload([
        'fund' => ['type' => 'TC22', 'percent' => 4],
        'withholding' => ['type' => 'RT01', 'reason' => 'A', 'rate' => 20, 'includes_fund' => true],
    ]))->assertCreated()
        ->assertJsonPath('data.withholding.amount', '208.00')
        ->assertJsonPath('data.withholding.includes_fund', true)
        ->assertJsonPath('data.total_due', '1060.80');

    $xpath = fiscalFlowXml($response->json('data.id'));

    expect(fiscalFlowValue($xpath, '//DatiCassaPrevidenziale/Ritenuta'))->toBe('SI')
        ->and(fiscalFlowValue($xpath, '//DatiRitenuta/ImportoRitenuta'))->toBe('208.00')
        ->and(fiscalFlowValue($xpath, '//ImportoPagamento'))->toBe('1060.80');
});

test('agent invoice withholds 23% on 50% of the commissions', function (): void {
    fiscalFlowApi()->postJson('/api/v1/sales/invoices', fiscalFlowPayload([
        'withholding' => ['type' => 'RT01', 'reason' => 'R', 'rate' => 23, 'taxable_percent' => 50],
    ]))->assertCreated()
        ->assertJsonPath('data.withholding.base', '500.00')
        ->assertJsonPath('data.withholding.amount', '115.00')
        ->assertJsonPath('data.total_due', '1105.00');
});

// ------------------------------------------------------------ Split payment

test('split payment invoice to a public administration: EsigibilitaIVA S and ImportoPagamento without VAT', function (): void {
    $response = fiscalFlowApi()->postJson('/api/v1/sales/invoices', fiscalFlowPayload([
        'contact_id' => $this->publicAdministration->id,
        'split_payment' => true,
    ], [
        ['description' => 'Servizio A', 'quantity' => 1, 'unit_price' => 600, 'tax_rate' => 22],
        ['description' => 'Servizio B', 'quantity' => 1, 'unit_price' => 400, 'tax_rate' => 10],
    ]))->assertCreated()
        ->assertJsonPath('data.vat_payability', 'S')
        ->assertJsonPath('data.total_due', '1000.00');

    $xpath = fiscalFlowXml($response->json('data.id'));
    $payabilities = [];

    foreach ($xpath->query('//DatiRiepilogo/EsigibilitaIVA') ?: [] as $node) {
        $payabilities[] = $node->textContent;
    }

    expect($payabilities)->toBe(['S', 'S'])
        ->and(fiscalFlowValue($xpath, '//ImportoTotaleDocumento'))->toBe('1172.00')
        ->and(fiscalFlowValue($xpath, '//ImportoPagamento'))->toBe('1000.00');
});

test('split payment is rejected with reverse charge or without VAT lines', function (): void {
    fiscalFlowApi()->postJson('/api/v1/sales/invoices', fiscalFlowPayload(['split_payment' => true, 'reverse_charge' => true]))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'split_payment_incompatible')
        ->assertJsonValidationErrors('split_payment');

    fiscalFlowApi()->postJson('/api/v1/sales/invoices', fiscalFlowPayload(['split_payment' => true], [
        ['description' => 'Esente', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0, 'natura' => 'N4'],
    ]))->assertUnprocessable()->assertJsonPath('code', 'split_payment_requires_vat');

    expect(Invoice::count())->toBe(0);
});

// ------------------------------------------------------------ Bollo

test('virtual stamp duty threshold on exempt lines', function (string $amount, bool $applied, float $gross): void {
    $response = fiscalFlowApi()->postJson('/api/v1/sales/invoices', fiscalFlowPayload([], [
        ['description' => 'Prestazione sanitaria', 'quantity' => 1, 'unit_price' => $amount, 'tax_rate' => 0, 'natura' => 'N4'],
    ]))->assertCreated()
        ->assertJsonPath('data.stamp_duty_applied', $applied)
        ->assertJsonPath('data.stamp_duty_amount', $applied ? '2.00' : null)
        ->assertJsonPath('data.total_gross', $gross);

    $xpath = fiscalFlowXml($response->json('data.id'));

    if ($applied) {
        expect(fiscalFlowChildren($xpath, '//DatiGeneraliDocumento'))->toBe(['TipoDocumento', 'Divisa', 'Data', 'Numero', 'DatiBollo', 'ImportoTotaleDocumento'])
            ->and(fiscalFlowValue($xpath, '//DatiBollo/BolloVirtuale'))->toBe('SI')
            ->and(fiscalFlowValue($xpath, '//DatiBollo/ImportoBollo'))->toBe('2.00');
    } else {
        expect($xpath->query('//DatiBollo')?->length)->toBe(0);
    }

    expect(fiscalFlowValue($xpath, '//DettaglioLinee/AliquotaIVA'))->toBe('0.00')
        ->and(fiscalFlowValue($xpath, '//DettaglioLinee/Natura'))->toBe('N4')
        ->and(fiscalFlowChildren($xpath, '//DatiRiepilogo'))->toBe(['AliquotaIVA', 'Natura', 'ImponibileImporto', 'Imposta', 'RiferimentoNormativo']);
})->with([
    '77.47 has no stamp duty' => ['77.47', false, 77.47],
    '77.48 has stamp duty' => ['77.48', true, 79.48],
]);

test('mixed taxed and exempt lines: stamp duty on the exempt sum, one summary per rate and natura', function (): void {
    $response = fiscalFlowApi()->postJson('/api/v1/sales/invoices', fiscalFlowPayload([], [
        ['description' => 'Servizio', 'quantity' => 1, 'unit_price' => 500, 'tax_rate' => 22],
        ['description' => 'Esente', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0, 'natura' => 'N4'],
    ]))->assertCreated()
        ->assertJsonPath('data.stamp_duty_applied', true)
        ->assertJsonPath('data.total_gross', 712.0)
        ->assertJsonPath('data.items.1.natura', 'N4');

    $xpath = fiscalFlowXml($response->json('data.id'));

    expect($xpath->query('//DatiRiepilogo')?->length)->toBe(2)
        ->and(fiscalFlowValue($xpath, '//DatiRiepilogo[1]/Imposta'))->toBe('110.00')
        ->and(fiscalFlowValue($xpath, '//DatiRiepilogo[2]/Natura'))->toBe('N4')
        ->and(fiscalFlowValue($xpath, '//DatiRiepilogo[2]/ImponibileImporto'))->toBe('100.00')
        ->and(fiscalFlowValue($xpath, '//ImportoTotaleDocumento'))->toBe('712.00');
});

// ------------------------------------------------------------ Forfettario

test('forfettario tenant: lines are N2.2, legal wording in Causale, stamp duty and no ritenuta', function (): void {
    fiscalFlowApi()->putJson('/api/v1/settings/preferences/fiscal', [
        'regime_fiscale' => 'RF19',
        // A default ritenuta is ignored under the flat-rate regime.
        'default_withholding' => ['type' => 'RT01', 'reason' => 'A', 'rate' => 20],
    ])->assertOk();

    $response = fiscalFlowApi()->postJson('/api/v1/sales/invoices', fiscalFlowPayload([], [
        ['description' => 'Consulenza', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 22],
    ]))->assertCreated()
        ->assertJsonPath('data.items.0.tax_rate', 0.0)
        ->assertJsonPath('data.items.0.natura', 'N2.2')
        ->assertJsonPath('data.withholding', null)
        ->assertJsonPath('data.stamp_duty_amount', '2.00')
        ->assertJsonPath('data.total_gross', 102.0)
        ->assertJsonPath('data.total_due', '102.00')
        ->assertJsonPath('data.legal_notices', config('italian_tax.forfettario.legal_notices'));

    $xpath = fiscalFlowXml($response->json('data.id'));
    $causali = [];

    foreach ($xpath->query('//DatiGeneraliDocumento/Causale') ?: [] as $node) {
        $causali[] = $node->textContent;
        expect(mb_strlen($node->textContent))->toBeLessThanOrEqual(200);
    }

    expect(fiscalFlowValue($xpath, '//CedentePrestatore/DatiAnagrafici/RegimeFiscale'))->toBe('RF19')
        ->and(fiscalFlowValue($xpath, '//DettaglioLinee/AliquotaIVA'))->toBe('0.00')
        ->and(fiscalFlowValue($xpath, '//DettaglioLinee/Natura'))->toBe('N2.2')
        ->and(fiscalFlowValue($xpath, '//DatiRiepilogo/RiferimentoNormativo'))->toBe(config('italian_tax.forfettario.riferimento_normativo'))
        ->and($xpath->query('//DatiRitenuta')?->length)->toBe(0)
        ->and(fiscalFlowValue($xpath, '//DatiBollo/ImportoBollo'))->toBe('2.00')
        ->and(fiscalFlowValue($xpath, '//ImportoTotaleDocumento'))->toBe('102.00')
        ->and(implode(' ', $causali))->toContain('190/2014');
});

test('forfettario tenant: explicit ritenuta, split payment or reverse charge are rejected', function (array $options, string $field): void {
    $this->tenant->update(['settings' => ['regime_fiscale' => 'RF19']]);

    fiscalFlowApi()->postJson('/api/v1/sales/invoices', fiscalFlowPayload($options))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'forfettario_incompatible')
        ->assertJsonValidationErrors($field);
})->with([
    'ritenuta' => [['withholding' => ['type' => 'RT01', 'reason' => 'A', 'rate' => 20]], 'withholding'],
    'split payment' => [['split_payment' => true], 'split_payment'],
    'reverse charge' => [['reverse_charge' => true], 'reverse_charge'],
]);

// ------------------------------------------------------------ ENASARCO

test('enasarco is rendered as DatiRitenuta RT04 within the annual ceiling', function (): void {
    EnasarcoAnnualLimit::create(['tenant_id' => $this->tenant->id, 'year' => 2026, 'rate' => '4.00', 'annual_minimum' => '0.00', 'annual_maximum_commission' => '100000.00']);
    Invoice::create([
        'tenant_id' => $this->tenant->id, 'contact_id' => $this->customer->id, 'invoice_type' => 'sdi_electronic', 'invoice_number' => 'AG-PREV',
        'date' => '2026-02-01', 'currency' => 'EUR', 'jurisdiction' => 'IT', 'total_net' => '99000.00', 'enasarco_commissions' => '99000.00', 'enasarco_amount' => '3960.00',
    ]);

    $response = fiscalFlowApi()->postJson('/api/v1/sales/invoices', fiscalFlowPayload(['enasarco' => ['enabled' => true]], [
        ['description' => 'Provvigioni marzo', 'quantity' => 1, 'unit_price' => 3000, 'tax_rate' => 22],
    ]))->assertCreated()
        ->assertJsonPath('data.enasarco.commissions', '3000.00')
        ->assertJsonPath('data.enasarco.contributory_base', '1000.00')
        ->assertJsonPath('data.enasarco.amount', '40.00')
        ->assertJsonPath('data.total_due', '3620.00');

    $xpath = fiscalFlowXml($response->json('data.id'));

    expect(fiscalFlowValue($xpath, '//DatiRitenuta/TipoRitenuta'))->toBe('RT04')
        ->and(fiscalFlowValue($xpath, '//DatiRitenuta/ImportoRitenuta'))->toBe('40.00')
        ->and(fiscalFlowValue($xpath, '//DatiRitenuta/AliquotaRitenuta'))->toBe('4.00')
        ->and(fiscalFlowValue($xpath, '//DettaglioLinee/Ritenuta'))->toBe('SI')
        ->and(fiscalFlowValue($xpath, '//ImportoPagamento'))->toBe('3620.00');

    fiscalFlowApi()->getJson("/api/v1/tax/enasarco/status?contact_id={$this->customer->id}&year=2026")
        ->assertOk()
        ->assertJsonPath('data.ytd_commissions', '102000.00')
        ->assertJsonPath('data.remaining_cap', '0.00');
});

test('enasarco without a configured rate for the year is rejected', function (): void {
    fiscalFlowApi()->postJson('/api/v1/sales/invoices', fiscalFlowPayload(['date' => '2001-05-10', 'due_date' => '2001-06-10', 'enasarco' => ['enabled' => true]]))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'enasarco_rate_not_configured')
        ->assertJsonValidationErrors('enasarco');

    fiscalFlowApi()->getJson("/api/v1/tax/enasarco/status?contact_id={$this->customer->id}&year=2001")
        ->assertUnprocessable()
        ->assertJsonPath('code', 'enasarco_rate_not_configured');
});

// ------------------------------------------------------------ Tenant defaults and preview

test('tenant default cassa applies when the payload omits it and is disabled by an explicit null', function (): void {
    fiscalFlowApi()->putJson('/api/v1/settings/preferences/fiscal', ['default_fund' => ['type' => 'TC22', 'percent' => 4]])->assertOk();

    fiscalFlowApi()->postJson('/api/v1/sales/invoices', fiscalFlowPayload())->assertCreated()
        ->assertJsonPath('data.fund.amount', '40.00')
        ->assertJsonPath('data.total_due', '1268.80');

    fiscalFlowApi()->postJson('/api/v1/sales/invoices', fiscalFlowPayload(['fund' => null]))->assertCreated()
        ->assertJsonPath('data.fund', null)
        ->assertJsonPath('data.total_due', '1220.00');
});

test('fiscal preview returns the same figures as invoice creation, as decimal strings', function (): void {
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

test('swiss invoices keep the per-line calculation and CHF rounding', function (): void {
    fiscalFlowApi()->postJson('/api/v1/sales/invoices', fiscalFlowPayload([
        'invoice_type' => 'ch_qr_bill', 'currency' => 'CHF', 'jurisdiction' => 'CH',
    ], [['description' => 'Beratung', 'quantity' => 1, 'unit_price' => 100.43, 'tax_rate' => 0]]))
        ->assertCreated()
        ->assertJsonPath('data.total_gross', 100.45)
        ->assertJsonPath('data.total_due', null);
});
