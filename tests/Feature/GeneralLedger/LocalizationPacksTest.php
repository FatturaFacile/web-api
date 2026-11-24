<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\Us1099Recipient;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use Modules\GeneralLedger\Services\Localization\FecExporter;
use Modules\GeneralLedger\Services\Localization\SiiVatBookExporter;

require_once __DIR__.'/../Compliance/ComplianceHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = complianceTenant();
    actingAsMember($this->tenant, 'accountant');
});

afterEach(fn () => app(TenantContext::class)->clear());

test('the French, Spanish and German charts import with their VAT accounts, and importing again creates nothing', function (string $template, string $code): void {
    $first = $this->postJson("/api/v1/general-ledger/account-templates/{$template}/import")->assertOk()->json('data');
    $again = $this->postJson("/api/v1/general-ledger/account-templates/{$template}/import")->assertOk()->json('data');

    expect($first['created'])->toBeGreaterThan(75)
        ->and($again)->toBe(['template' => $template, 'created' => 0, 'skipped' => $first['created']])
        ->and(ChartOfAccount::where('account_code', $code)->count())->toBe(1)
        ->and(ChartOfAccount::where('account_code', $code)->value('standard'))->toBe($template);
})->with([['fr_pcg', '44571'], ['es_pgc', '477'], ['de_skr03', '1776'], ['de_skr04', '3806']]);

test('a VAT code pack is listed and imported idempotently as shared codes', function (): void {
    $packs = $this->getJson('/api/v1/general-ledger/vat-code-packs')->assertOk()->json('data');
    expect(collect($packs)->pluck('country')->all())->toBe(['FR', 'ES', 'DE']);

    $first = $this->postJson('/api/v1/general-ledger/vat-code-packs/FR/import')->assertOk()->json('data');
    $again = $this->postJson('/api/v1/general-ledger/vat-code-packs/FR/import')->assertOk()->json('data');

    expect($first)->toBe(['country' => 'FR', 'created' => 7, 'updated' => 0])
        ->and($again)->toBe(['country' => 'FR', 'created' => 0, 'updated' => 7])
        ->and(DB::table('vat_codes')->where('code', 'FR_TVA_20')->count())->toBe(1)
        ->and((float) DB::table('vat_codes')->where('code', 'FR_TVA_55')->value('rate'))->toBe(5.5)
        ->and((bool) DB::table('vat_codes')->where('code', 'FR_TVA_AUTO')->value('is_reverse_charge'))->toBeTrue();

    $this->postJson('/api/v1/general-ledger/vat-code-packs/ES/import')->assertOk();
    expect((float) DB::table('vat_codes')->where('code', 'ES_IVA_21')->value('rate'))->toBe(21.0);
    $german = $this->postJson('/api/v1/general-ledger/vat-code-packs/DE/import')->assertOk()->json('data');
    $germanAgain = $this->postJson('/api/v1/general-ledger/vat-code-packs/de/import')->assertOk()->json('data');
    expect($german)->toBe(['country' => 'DE', 'created' => 8, 'updated' => 0])
        ->and($germanAgain)->toBe(['country' => 'DE', 'created' => 0, 'updated' => 8])
        ->and((float) DB::table('vat_codes')->where('code', 'DE_UST_19')->value('rate'))->toBe(19.0)
        ->and((float) DB::table('vat_codes')->where('code', 'DE_UST_7')->value('rate'))->toBe(7.0)
        ->and((bool) DB::table('vat_codes')->where('code', 'DE_UST_13B')->value('is_reverse_charge'))->toBeTrue()
        ->and((bool) DB::table('vat_codes')->where('code', 'DE_UST_IGE')->value('is_reverse_charge'))->toBeTrue()
        ->and(DB::table('vat_codes')->where('code', 'DE_UST_IGL')->value('nature'))->toBe('IGL');
    $this->postJson('/api/v1/general-ledger/vat-code-packs/XX/import')->assertNotFound();
});

test('the FEC has the 18 columns, YYYYMMDD dates, decimal commas and only the posted lines of the tenant', function (): void {
    $year = app(OpenFiscalYearAction::class)->execute((string) $this->tenant->id, 2026);
    $account = fn (string $code, string $name, string $type, string $balance): ChartOfAccount => ChartOfAccount::create([
        'tenant_id' => $this->tenant->id, 'account_code' => $code, 'name' => $name, 'account_class' => $type, 'account_type' => $type,
        'normal_balance' => $balance, 'is_active' => true,
    ]);
    $bank = $account('512', 'Banques', 'asset', 'debit');
    $sales = $account('706', 'Prestations de services', 'revenue', 'credit');

    app(DoubleEntryPostingServiceInterface::class)->createAndPost([
        'tenant_id' => (string) $this->tenant->id,
        'fiscal_period_id' => app(FiscalPeriodResolver::class)->forDate((string) $this->tenant->id, '2026-03-10')->id,
        'posting_date' => '2026-03-10',
        'entry_number' => 'FEC-'.Str::upper(Str::random(6)),
        'description' => "Encaissement\tclient",
        'status' => 'posted',
        'lines' => [
            ['account_id' => $bank->id, 'debit_amount' => 1234.5, 'credit_amount' => 0.0],
            ['account_id' => $sales->id, 'debit_amount' => 0.0, 'credit_amount' => 1234.5],
        ],
    ]);

    $other = Tenant::create(['name' => 'Autre', 'legal_name' => 'Autre SARL', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(OpenFiscalYearAction::class)->execute((string) $other->id, 2026);

    $response = $this->get('/api/v1/general-ledger/fec/export?fiscal_year_id='.$year->id)->assertOk();
    $lines = explode("\r\n", trim($response->getContent()));
    $header = explode("\t", $lines[0]);
    $row = explode("\t", $lines[1]);

    expect($header)->toBe(FecExporter::Columns)
        ->and($lines)->toHaveCount(3)
        ->and($row)->toHaveCount(18)
        ->and($row[3])->toBe('20260310')
        ->and($row[4])->toBe('512')
        ->and($row[10])->toBe('Encaissement client')
        ->and($row[11])->toBe('1234,50')
        ->and($row[12])->toBe('0,00')
        ->and($response->headers->get('Content-Disposition'))->toContain('FEC20261231.txt');

    $this->get('/api/v1/general-ledger/fec/export?fiscal_year_id='.Str::uuid())->assertNotFound();
    $this->get('/api/v1/general-ledger/fec/export')->assertUnprocessable();
});

test('the SII book lists sales as emitidas with R1 for credit notes and purchases as recibidas', function (): void {
    $register = ['lines' => [
        ['protocol_number' => 1, 'posting_date' => '2026-04-15', 'document_date' => '2026-04-12', 'document_number' => 'A-1', 'document_type' => 'invoice', 'partner_name' => 'Cliente SL', 'taxable_amount' => '100.00', 'vat_rate' => '21.00', 'vat_amount' => '21.00', 'vat_treatment' => null],
        ['protocol_number' => 2, 'posting_date' => '2026-04-20', 'document_date' => '2026-04-20', 'document_number' => 'A-2', 'document_type' => 'credit_note', 'partner_name' => 'Cliente SL', 'taxable_amount' => '-50.00', 'vat_rate' => '21.00', 'vat_amount' => '-10.50', 'vat_treatment' => null],
    ], 'totals' => ['taxable' => '50.00', 'tax' => '10.50']];
    $exporter = app(SiiVatBookExporter::class);

    $sales = array_map(fn (string $line): array => str_getcsv($line, ';'), explode("\r\n", trim(ltrim($exporter->csv($register, 'sales'), "\xEF\xBB\xBF"))));
    $purchases = array_map(fn (string $line): array => str_getcsv($line, ';'), explode("\r\n", trim(ltrim($exporter->csv($register, 'purchases'), "\xEF\xBB\xBF"))));

    expect($sales[0])->toContain('CuotaRepercutida')
        ->and($sales[1])->toBe(['2026', '04', 'F1', '12-04-2026', 'A-1', '01', 'S1', '21.00', '100.00', '21.00', 'Cliente SL', '', '', ''])
        ->and($sales[2][2])->toBe('R1')
        ->and($sales[2][8])->toBe('-50.00')
        ->and($purchases[0])->toContain('CuotaDeducible')
        ->and($purchases[1][5])->toBe('15-04-2026');

    $this->get('/api/v1/general-ledger/sii/sales/export?from=2026-01-01&to=2026-12-31')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $this->get('/api/v1/general-ledger/sii/purchases/export?from=2026-12-31&to=2026-01-01')->assertUnprocessable();
});

test('US sales tax needs nexus on the sale date and adds the state, county and city rates in force', function (): void {
    $state = $this->postJson('/api/v1/general-ledger/us-tax/jurisdictions', ['code' => 'CA', 'name' => 'California', 'state' => 'ca', 'level' => 'state'])->assertCreated()->json('data.id');
    $county = $this->postJson('/api/v1/general-ledger/us-tax/jurisdictions', ['code' => 'CA-LA', 'name' => 'Los Angeles County', 'state' => 'CA', 'level' => 'county', 'county' => 'Los Angeles'])->assertCreated()->json('data.id');
    $this->postJson('/api/v1/general-ledger/us-tax/jurisdictions', ['code' => 'CA-SF', 'name' => 'San Francisco', 'state' => 'CA', 'level' => 'city'])->assertUnprocessable();

    $this->postJson("/api/v1/general-ledger/us-tax/jurisdictions/{$state}/rates", ['rate_percent' => 7.0, 'effective_from' => '2020-01-01'])->assertCreated();
    $this->postJson("/api/v1/general-ledger/us-tax/jurisdictions/{$state}/rates", ['rate_percent' => 7.25, 'effective_from' => '2025-01-01'])->assertCreated();
    $this->postJson("/api/v1/general-ledger/us-tax/jurisdictions/{$county}/rates", ['rate_percent' => 2.25, 'effective_from' => '2020-01-01', 'applies_to_shipping' => true])->assertCreated();

    $payload = ['state' => 'CA', 'date' => '2026-05-01', 'taxable_amount' => 100, 'shipping_amount' => 10, 'county' => 'los angeles'];

    $noNexus = $this->postJson('/api/v1/general-ledger/us-tax/calculate', $payload)->assertOk()->json('data');
    expect($noNexus)->toMatchArray(['has_nexus' => false, 'tax_total' => '0.00', 'reason' => 'no_nexus']);

    $this->postJson('/api/v1/general-ledger/us-tax/nexus', ['state' => 'CA', 'nexus_type' => 'economic', 'established_on' => '2026-01-01'])->assertCreated();
    expect($this->getJson('/api/v1/general-ledger/us-tax/nexus')->assertOk()->json('data'))->toHaveCount(1);

    $taxed = $this->postJson('/api/v1/general-ledger/us-tax/calculate', $payload)->assertOk()->json('data');
    expect($taxed['has_nexus'])->toBeTrue()
        ->and($taxed['lines'])->toHaveCount(2)
        ->and($taxed['rate_percent'])->toBe('9.5000')
        ->and($taxed['tax_total'])->toBe('9.73');

    $before = $this->postJson('/api/v1/general-ledger/us-tax/calculate', [...$payload, 'date' => '2025-12-31'])->assertOk()->json('data');
    expect($before['has_nexus'])->toBeFalse();

    $this->getJson('/api/v1/general-ledger/us-tax/jurisdictions?state=ca')->assertOk()->assertJsonCount(2, 'data');
});

test('US tax data is isolated per tenant', function (): void {
    $this->postJson('/api/v1/general-ledger/us-tax/jurisdictions', ['code' => 'TX', 'name' => 'Texas', 'state' => 'TX', 'level' => 'state'])->assertCreated();

    $other = Tenant::create(['name' => 'Otra', 'legal_name' => 'Otra LLC', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->clear();
    actingAsMember($other, 'accountant');

    $this->getJson('/api/v1/general-ledger/us-tax/jurisdictions')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson('/api/v1/general-ledger/us-tax/jurisdictions', ['code' => 'TX', 'name' => 'Texas', 'state' => 'TX', 'level' => 'state'])->assertCreated();
});

test('a read-only role can neither read nor change the US tax and pack data', function (): void {
    $viewer = Tenant::create(['name' => 'Solo lettura', 'legal_name' => 'Solo lettura Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->clear();
    actingAsMember($viewer, 'accountant_readonly');

    $this->getJson('/api/v1/general-ledger/us-tax/nexus')->assertForbidden();
    $this->postJson('/api/v1/general-ledger/us-tax/nexus', ['state' => 'CA', 'nexus_type' => 'physical', 'established_on' => '2026-01-01'])->assertForbidden();
    $this->postJson('/api/v1/general-ledger/vat-code-packs/FR/import')->assertForbidden();
});

test('1099 data capture encrypts the TIN, validates boxes and flags reportable recipients over the threshold', function (): void {
    $tin = (string) random_int(100000000, 999999999);
    $recipient = $this->postJson('/api/v1/general-ledger/us-1099/recipients', ['name' => 'Jane Contractor', 'tin_type' => 'ssn', 'tin' => $tin, 'state' => 'ny'])->assertCreated()->json('data');

    expect($recipient)->not->toHaveKey('tin')
        ->and($recipient['tin_last4'])->toBe(substr($tin, -4))
        ->and(DB::table('us_1099_recipients')->where('id', $recipient['id'])->value('tin'))->not->toContain($tin)
        ->and(Us1099Recipient::find($recipient['id'])->tin)->toBe($tin);

    $this->postJson('/api/v1/general-ledger/us-1099/recipients', ['name' => 'Bad TIN', 'tin' => '12'])->assertUnprocessable();
    $this->postJson('/api/v1/general-ledger/us-1099/payments', ['recipient_id' => $recipient['id'], 'paid_on' => '2026-02-01', 'amount' => 1500, 'form_type' => '1099-NEC', 'box' => '2'])->assertUnprocessable();

    foreach ([1500, 800] as $amount) {
        $this->postJson('/api/v1/general-ledger/us-1099/payments', ['recipient_id' => $recipient['id'], 'paid_on' => '2026-02-01', 'amount' => $amount, 'form_type' => '1099-NEC', 'box' => '1'])->assertCreated();
    }

    $this->postJson('/api/v1/general-ledger/us-1099/payments', ['recipient_id' => $recipient['id'], 'paid_on' => '2025-02-01', 'amount' => 5000, 'form_type' => '1099-NEC', 'box' => '1'])->assertCreated();

    $summary = $this->getJson('/api/v1/general-ledger/us-1099/summary?year=2026')->assertOk()->json('data');
    expect($summary['recipients'])->toHaveCount(1)
        ->and($summary['recipients'][0]['reportable'])->toBeTrue()
        ->and($summary['recipients'][0]['boxes'][0])->toBe(['form_type' => '1099-NEC', 'box' => '1', 'amount' => '2300.00', 'meets_threshold' => true])
        ->and($summary['recipients'][0]['missing_w9'])->toBeTrue()
        ->and($this->getJson('/api/v1/general-ledger/us-1099/recipients')->assertOk()->json('data.0'))->not->toHaveKey('tin');
});
