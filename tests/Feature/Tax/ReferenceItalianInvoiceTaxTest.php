<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Invoicing\InvoiceService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\GeneralLedger\Models\EnasarcoParameter;

uses(RefreshDatabase::class);

/**
 * Independent reference check of the Italian invoice taxes: stamp duty (bollo), withholding tax (ritenuta), pension fund
 * contribution (cassa), split payment and Enasarco.
 *
 * Expected values are computed in the test with plain bcmath, rounded to two decimals half away from zero, from the
 * inputs of each scenario. Sources:
 *
 * - Stamp duty: EUR 2.00 on documents with no VAT above EUR 77.47; exactly 77.47 is not subject. D.P.R. 642/1972, Tariffa
 *   parte I, art. 13 c. 1 and nota 2, as written in config/fiscal_it.php of this repository.
 * - Withholding: base x percent, the base being net (plus the fund when it is subject) and, when a base percentage is
 *   given, that share of it (art. 25 and 25-bis D.P.R. 600/1973: the percentages themselves are chosen by the user in the
 *   invoice and are NOT asserted as legal rates here). The amount due is the gross total minus the withholding.
 * - Pension fund: the contribution is a percentage of the net, enters the VAT base at the highest rate of the document
 *   (FatturaPA DatiCassaPrevidenziale, AliquotaIVA).
 * - Split payment (art. 17-ter D.P.R. 633/1972): the customer pays net of the VAT, which it pays to the Erario.
 * - Enasarco 2026: ceiling per agency relationship EUR 45,717 (single mandate) and EUR 30,478 (multiple mandates),
 *   contribution rate 17%, shared equally between principal and agent, so 8.5% is withheld from the agent (Fondazione
 *   Enasarco, "Minimali e massimali 2026", read 2026-11-11, and the 2025 financial statements, rate 17% from 2020; the
 *   equal split from the Enasarco guide). Whether the 17% still applies to 2026 is not stated on the ceilings page: UNVERIFIED.
 *
 * Not asserted because not verified on a primary source: the F24 code of the withholding (config says so), the 23% / 50%
 * agent withholding figures, and the stamp duty on exempt (N4) invoices of an ordinary regime, which this code leaves to
 * the user (it applies it automatically only under the flat-rate regime and declarations of intent).
 */
beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'Rossi', 'legal_name' => 'Rossi', 'vat_number' => '00811720580', 'fiscal_code' => '00811720580', 'country' => 'IT', 'default_currency' => 'EUR',
        'settings' => ['street' => 'Via Verdi 1', 'postal_code' => '20121', 'city' => 'Milano', 'province' => 'MI'],
    ]);
    $this->customer = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Bianchi SpA', 'country' => 'IT', 'vat_number' => '01234567897', 'sdi_code' => 'ABC1234', 'email' => 'bianchi@example.com']);
    app(TenantContext::class)->setTenant($this->tenant);
    fiscalCalendar($this->tenant, 2026);
});

function refPct(string $amount, string $percent): string
{
    return bcround(bcdiv(bcmul($amount, $percent, 6), '100', 6), 2, RoundingMode::HalfAwayFromZero);
}

/**
 * @param  list<array{0: string, 1: string}>  $lines  net, VAT rate
 * @param  array<string, mixed>  $data
 */
function refInvoice(array $lines, array $data = []): Invoice
{
    return app(InvoiceService::class)->createInvoice([
        'contact_id' => test()->customer->id,
        'invoice_type' => 'standard',
        'date' => '2026-03-01',
        'currency' => 'EUR',
        'jurisdiction' => 'IT',
        'items' => array_map(static fn (array $line): array => ['description' => 'Service', 'quantity' => 1, 'unit_price' => (float) $line[0], 'tax_rate' => (float) $line[1]], $lines),
        ...$data,
    ]);
}

dataset('withholding and fund scenarios', [
    // lines, data, fund, withholding, vat, gross, due
    'W1 20% on the net' => [[['1000.00', '22']], ['withholding_tax_enabled' => true, 'withholding_tax_percent' => 20], '0.00', '200.00', '220.00', '1220.00', '1020.00'],
    'W2 fund 4% subject to the withholding' => [[['1000.00', '22']], ['fund_enabled' => true, 'fund_type' => 'TC22', 'fund_percent' => 4, 'withholding_tax_enabled' => true, 'withholding_tax_percent' => 20, 'fund_subject_to_withholding' => true], '40.00', '208.00', '228.80', '1268.80', '1060.80'],
    'W3 fund 4% not subject to the withholding' => [[['1000.00', '22']], ['fund_enabled' => true, 'fund_type' => 'TC22', 'fund_percent' => 4, 'withholding_tax_enabled' => true, 'withholding_tax_percent' => 20, 'fund_subject_to_withholding' => false], '40.00', '200.00', '228.80', '1268.80', '1068.80'],
    'W4 base percentage 50% at 23%' => [[['1000.00', '22']], ['withholding_tax_enabled' => true, 'withholding_tax_percent' => 23, 'withholding_tax_base_percent' => 50], '0.00', '115.00', '220.00', '1220.00', '1105.00'],
    'W5 withholding rounds half up' => [[['333.33', '22']], ['withholding_tax_enabled' => true, 'withholding_tax_percent' => 20], '0.00', '66.67', '73.33', '406.66', '339.99'],
    'W6 two VAT rates' => [[['600.00', '22'], ['400.00', '10']], ['withholding_tax_enabled' => true, 'withholding_tax_percent' => 20], '0.00', '200.00', '172.00', '1172.00', '972.00'],
    'W7 fund takes the highest rate of the document' => [[['600.00', '22'], ['400.00', '10']], ['fund_enabled' => true, 'fund_type' => 'TC22', 'fund_percent' => 4], '40.00', '0.00', '180.80', '1220.80', '1220.80'],
    'W8 no withholding, no fund' => [[['250.00', '4']], [], '0.00', '0.00', '10.00', '260.00', '260.00'],
]);

it('computes withholding, pension fund, VAT and amount due like the hand reference', function (array $lines, array $data, string $fund, string $withholding, string $vat, string $gross, string $due): void {
    $invoice = refInvoice($lines, $data);

    // Reference: fund = net x %, withholding = (net [+ fund]) [x base%] x %, VAT per rate on the base plus the fund at the highest rate.
    $net = array_reduce($lines, static fn (string $carry, array $line): string => bcadd($carry, $line[0], 2), '0');
    $referenceFund = isset($data['fund_percent']) ? refPct($net, (string) $data['fund_percent']) : '0.00';
    $base = ($data['fund_subject_to_withholding'] ?? false) ? bcadd($net, $referenceFund, 2) : $net;
    $base = isset($data['withholding_tax_base_percent']) ? refPct($base, (string) $data['withholding_tax_base_percent']) : $base;
    $referenceWithholding = isset($data['withholding_tax_percent']) ? refPct($base, (string) $data['withholding_tax_percent']) : '0.00';
    $highest = array_reduce($lines, static fn (string $carry, array $line): string => bccomp($line[1], $carry, 2) > 0 ? $line[1] : $carry, '0');
    $byRate = [];

    foreach ($lines as $line) {
        $byRate[$line[1]] = bcadd($byRate[$line[1]] ?? '0', $line[0], 2);
    }

    if (bccomp($referenceFund, '0', 2) > 0) {
        $byRate[$highest] = bcadd($byRate[$highest] ?? '0', $referenceFund, 2);
    }

    $referenceVat = '0';

    foreach ($byRate as $rate => $taxable) {
        $referenceVat = bcadd($referenceVat, refPct($taxable, (string) $rate), 2);
    }

    $referenceGross = bcadd(bcadd($net, $referenceFund, 2), $referenceVat, 2);
    $referenceDue = bcsub($referenceGross, $referenceWithholding, 2);

    expect([$referenceFund, $referenceWithholding, $referenceVat, $referenceGross, $referenceDue])->toBe([$fund, $withholding, $vat, $gross, $due])
        ->and(bcadd((string) ($invoice->fund_amount ?? '0'), '0', 2))->toBe($fund)
        ->and(bcadd((string) ($invoice->withholding_tax_amount ?? '0'), '0', 2))->toBe($withholding)
        ->and(bcadd((string) $invoice->total_tax, '0', 2))->toBe($vat)
        ->and(bcadd((string) $invoice->total_gross, '0', 2))->toBe($gross)
        ->and($invoice->amountDue())->toBe($due);
})->with('withholding and fund scenarios');

dataset('stamp duty scenarios', [
    'B1 1 cent' => ['0.01', false],
    'B2 below the threshold' => ['77.46', false],
    'B3 at the threshold: not subject' => ['77.47', false],
    'B4 one cent above: subject' => ['77.48', true],
    'B5 round amount' => ['100.00', true],
    'B6 large amount' => ['25000.00', true],
]);

it('charges the EUR 2.00 stamp duty on a flat-rate invoice above EUR 77.47 only', function (string $net, bool $subject): void {
    $this->tenant->update(['settings' => [...$this->tenant->settings, 'regime_fiscale' => 'RF19']]);
    app(TenantContext::class)->setTenant($this->tenant->refresh());

    $invoice = refInvoice([[$net, '0']]);
    $expectedGross = $subject ? bcadd($net, '2.00', 2) : $net;

    expect((bool) $invoice->stamp_duty_applied)->toBe($subject)
        ->and(bcadd((string) $invoice->total_gross, '0', 2))->toBe($expectedGross)
        ->and(bcadd((string) $invoice->total_tax, '0', 2))->toBe('0.00');
})->with('stamp duty scenarios');

dataset('split payment scenarios', [
    'P1 one rate' => [[['1000.00', '22']], [], '1220.00', '1000.00'],
    'P2 two rates' => [[['600.00', '22'], ['400.00', '10']], [], '1172.00', '1000.00'],
    'P3 with withholding 20%' => [[['1000.00', '22']], ['withholding_tax_enabled' => true, 'withholding_tax_percent' => 20], '1220.00', '800.00'],
    'P4 rate 4%' => [[['2500.00', '4']], [], '2600.00', '2500.00'],
]);

it('lets the customer pay the net of the VAT on a split payment invoice', function (array $lines, array $data, string $gross, string $due): void {
    $invoice = refInvoice($lines, [...$data, 'split_payment' => true]);

    expect(bcadd((string) $invoice->total_gross, '0', 2))->toBe($gross)
        ->and($invoice->amountDue())->toBe($due);
})->with('split payment scenarios');

function refEnasarco(string $agentType, string $ceiling, string $net, string $alreadySubject = '0'): array
{
    EnasarcoParameter::create([
        'tenant_id' => test()->tenant->id, 'year' => 2026, 'agent_type' => $agentType, 'rate_percent' => 17,
        'agent_share_percent' => 50, 'annual_ceiling' => $ceiling, 'source' => 'Fondazione Enasarco, Minimali e massimali 2026 (ceiling); rate 17% from the 2025 financial statements',
    ]);

    $room = bcsub($ceiling, $alreadySubject, 2);
    $room = bccomp($room, '0', 2) < 0 ? '0' : $room;
    $base = bccomp($net, $room, 2) > 0 ? $room : $net;

    return ['base' => $base, 'amount' => refPct($base, '8.5')];
}

dataset('enasarco scenarios', [
    'E1 single mandate, far from the ceiling' => ['single_mandate', '45717', ['10000.00'], '850.00'],
    'E2 multiple mandates, far from the ceiling' => ['multi_mandate', '30478', ['10000.00'], '850.00'],
    'E3 single mandate, partly above the ceiling' => ['single_mandate', '45717', ['44000.00', '5000.00'], '145.95'],
    'E4 single mandate, ceiling already reached' => ['single_mandate', '45717', ['45717.00', '3000.00'], '0.00'],
    'E5 multiple mandates, exactly at the ceiling' => ['multi_mandate', '30478', ['30478.00'], '2590.63'],
    'E6 small amount rounds half up' => ['single_mandate', '45717', ['333.33'], '28.33'],
]);

it('withholds the agent share of Enasarco up to the annual ceiling like the hand reference', function (string $agentType, string $ceiling, array $nets, string $lastAmount): void {
    $already = '0';
    $reference = ['base' => '0', 'amount' => '0.00'];

    foreach ($nets as $index => $net) {
        $reference = refEnasarco($index === 0 ? $agentType : $agentType, $ceiling, $net, $already);
        $invoice = refInvoice([[$net, '22']], ['enasarco_enabled' => true, 'enasarco_agent_type' => $agentType]);

        expect(bcadd((string) $invoice->enasarco_base, '0', 2))->toBe(bcadd($reference['base'], '0', 2))
            ->and(bcadd((string) $invoice->enasarco_amount, '0', 2))->toBe($reference['amount']);

        // The invoices of a year only count when posted: post this one so the next sees the room used.
        Invoice::query()->whereKey($invoice->id)->update(['status' => 'posted']);
        $already = bcadd($already, $reference['base'], 2);
        EnasarcoParameter::query()->where('year', 2026)->delete();
    }

    expect($reference['amount'])->toBe($lastAmount);
})->with('enasarco scenarios');
