<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\PassiveInvoice;
use App\Models\SupplierPayment;
use App\Models\Tenant;
use App\Services\Auth\TaxIdentifier;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use Modules\GeneralLedger\Services\Localization\SiiVatBookExporter;
use Tests\Support\WithoutSeeding;

require_once __DIR__.'/../Compliance/ComplianceHelpers.php';

uses(WithoutSeeding::class, RefreshDatabase::class);

afterEach(fn () => app(TenantContext::class)->clear());

function gapTenant(string $country, string $currency = 'EUR', string $role = 'accountant'): Tenant
{
    $tenant = Tenant::create(['name' => "Gap {$country}", 'legal_name' => "Gap {$country} Ltd", 'country' => $country, 'default_currency' => $currency]);
    app(OpenFiscalYearAction::class)->execute((string) $tenant->id, 2026);
    actingAsMember($tenant, $role);

    return $tenant;
}

function gapAccount(Tenant $tenant, string $code, string $type, string $balance): ChartOfAccount
{
    return ChartOfAccount::firstOrCreate(
        ['tenant_id' => $tenant->id, 'account_code' => $code],
        ['name' => "Account {$code}", 'account_class' => $type, 'account_type' => $type, 'normal_balance' => $balance, 'is_active' => true],
    );
}

/**
 * @param  list<array<string, mixed>>  $lines
 */
function gapEntry(Tenant $tenant, string $date, string $source, array $lines, ?string $number = null): JournalEntry
{
    return app(DoubleEntryPostingServiceInterface::class)->createAndPost([
        'tenant_id' => (string) $tenant->id,
        'fiscal_period_id' => app(FiscalPeriodResolver::class)->forDate((string) $tenant->id, $date)->id,
        'source_document_type' => $source,
        'source_document_id' => (string) Str::uuid7(),
        'document_number' => $number ?? 'DOC-'.Str::upper(Str::random(6)),
        'entry_number' => 'GAP-'.Str::upper(Str::random(8)),
        'description' => 'Gap entry',
        'posting_date' => $date,
        'document_date' => $date,
        'status' => 'posted',
        'lines' => $lines,
    ]);
}

/**
 * A sale: bank debit, revenue credit and an output VAT line on the given accounts.
 */
function gapSale(Tenant $tenant, string $date, string $net, string $tax, string $rate, string $revenue, string $outputVat, string $bank = '1200'): JournalEntry
{
    return gapEntry($tenant, $date, 'sales_invoice', [
        ['account_id' => gapAccount($tenant, $bank, 'asset', 'debit')->id, 'debit_amount' => bcadd($net, $tax, 2), 'credit_amount' => 0],
        ['account_id' => gapAccount($tenant, $revenue, 'revenue', 'credit')->id, 'debit_amount' => 0, 'credit_amount' => $net],
        ['account_id' => gapAccount($tenant, $outputVat, 'liability', 'credit')->id, 'debit_amount' => 0, 'credit_amount' => $tax, 'vat_tax_amount' => $tax, 'vat_base_amount' => $net, 'vat_rate_percent' => $rate],
    ]);
}

/**
 * A supplier invoice: expense debit, input VAT debit and the supplier credit; a reverse charge adds the output VAT credit.
 */
function gapPurchase(Tenant $tenant, string $date, string $net, string $tax, string $rate, string $expense, string $inputVat, ?string $outputVat = null, ?Contact $supplier = null): JournalEntry
{
    $supplier ??= Contact::create(['tenant_id' => (string) $tenant->id, 'company_name' => 'Supplier', 'type' => 'supplier', 'country' => $tenant->country]);
    $reverse = $outputVat !== null;
    $vat = ['vat_tax_amount' => $tax, 'vat_base_amount' => $net, 'vat_rate_percent' => $rate, ...($reverse ? ['vat_treatment' => 'reverse_charge'] : [])];
    $lines = [
        ['account_id' => gapAccount($tenant, $expense, 'expense', 'debit')->id, 'debit_amount' => $net, 'credit_amount' => 0],
        ['account_id' => gapAccount($tenant, $inputVat, 'asset', 'debit')->id, 'debit_amount' => $tax, 'credit_amount' => 0, ...$vat],
        ['account_id' => gapAccount($tenant, '4010', 'liability', 'credit')->id, 'debit_amount' => 0, 'credit_amount' => $reverse ? $net : bcadd($net, $tax, 2), 'partner_type' => 'supplier', 'partner_id' => (string) $supplier->id],
    ];

    if ($reverse) {
        $lines[] = ['account_id' => gapAccount($tenant, $outputVat, 'liability', 'credit')->id, 'debit_amount' => 0, 'credit_amount' => $tax, ...$vat];
    }

    return gapEntry($tenant, $date, 'vendor_invoice', $lines);
}

/**
 * @param  array<string, string>  $boxes
 * @return array<string, string>
 */
function gapBoxes(array $return): array
{
    return collect($return['boxes'])->pluck('amount', 'code')->all();
}

test('the company country can be set to every supported country and stays fixed once entries exist', function (): void {
    $tenant = Tenant::create(['name' => 'Gap IT', 'legal_name' => 'Gap IT Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    actingAsMember($tenant, 'owner');

    foreach (['FR', 'ES', 'DE', 'US', 'CH'] as $country) {
        $this->putJson('/api/v1/settings/tenant', ['country' => $country])->assertOk();
        expect(Tenant::query()->find($tenant->id)->country)->toBe($country);
    }

    $this->putJson('/api/v1/settings/tenant', ['country' => 'XX'])->assertUnprocessable()->assertJsonValidationErrors('country');
    $this->putJson('/api/v1/settings/tenant', ['name' => 'Renamed'])->assertOk();
    expect(Tenant::query()->find($tenant->id)->country)->toBe('CH');

    app(OpenFiscalYearAction::class)->execute((string) $tenant->id, 2026);
    gapSale($tenant, '2026-03-10', '100.00', '8.10', '8.1', '3200', '2200');

    $this->putJson('/api/v1/settings/tenant', ['country' => 'DE'])->assertUnprocessable()->assertJsonValidationErrors('country');
    $this->putJson('/api/v1/settings/tenant', ['country' => 'CH', 'name' => 'Same country'])->assertOk();
    expect(Tenant::query()->find($tenant->id)->country)->toBe('CH');
});

test('the tax identifier finds the tenant of every supported country', function (): void {
    $french = Tenant::create(['name' => 'FR', 'legal_name' => 'FR SARL', 'country' => 'FR', 'default_currency' => 'EUR', 'vat_number' => 'FR 40 303265045']);
    $spanish = Tenant::create(['name' => 'ES', 'legal_name' => 'ES SL', 'country' => 'ES', 'default_currency' => 'EUR', 'vat_number' => 'ESB12345674']);
    $german = Tenant::create(['name' => 'DE', 'legal_name' => 'DE GmbH', 'country' => 'DE', 'default_currency' => 'EUR', 'vat_number' => 'DE123456789']);
    $american = Tenant::create(['name' => 'US', 'legal_name' => 'US LLC', 'country' => 'US', 'default_currency' => 'USD', 'vat_number' => '12-3456789']);
    $italian = Tenant::create(['name' => 'IT', 'legal_name' => 'IT Srl', 'country' => 'IT', 'default_currency' => 'EUR', 'vat_number' => 'IT40303265045']);

    expect(TaxIdentifier::normalize('FR 40 303265045', 'FR'))->toBe('40303265045')
        ->and(TaxIdentifier::normalize('ES-B12345674', 'ES'))->toBe('B12345674')
        ->and(TaxIdentifier::normalize('DE 123 456 789', 'DE'))->toBe('123456789')
        ->and(TaxIdentifier::normalize('12-3456789', 'US'))->toBe('123456789')
        ->and(TaxIdentifier::normalize('12345', 'DE'))->toBeNull()
        ->and(TaxIdentifier::findTenant('40303265045', 'FR')?->id)->toBe($french->id)
        ->and(TaxIdentifier::findTenant('B12345674', 'ES')?->id)->toBe($spanish->id)
        ->and(TaxIdentifier::findTenant('123456789', 'DE')?->id)->toBe($german->id)
        ->and(TaxIdentifier::findTenant('123456789', 'US')?->id)->toBe($american->id)
        ->and(TaxIdentifier::findTenant('40303265045')?->id)->toBe($italian->id)
        ->and(TaxIdentifier::findTenant('40303265045', 'FR')?->id)->not->toBe($italian->id)
        ->and(TaxIdentifier::findTenant('123456789', 'FR'))->toBeNull();
});

test('the French CA3 is computed from the ledger and exported', function (): void {
    $tenant = gapTenant('FR');
    gapSale($tenant, '2026-04-10', '1000.00', '200.00', '20', '706', '44571');
    gapSale($tenant, '2026-04-12', '200.00', '20.00', '10', '706', '44571');
    gapSale($tenant, '2026-04-15', '300.00', '0.00', '0', '707', '44571');
    gapPurchase($tenant, '2026-04-20', '500.00', '100.00', '20', '606', '44566');
    gapSale($tenant, '2026-07-01', '999.00', '199.80', '20', '706', '44571');

    $return = $this->getJson('/api/v1/general-ledger/fr/ca3?from=2026-04-01&to=2026-06-30&previous_credit=15')->assertOk()->json('data');
    $boxes = gapBoxes($return);

    expect($return['form'])->toBe('CA3')
        ->and($boxes['A'])->toBe('1500.00')
        ->and($boxes['05'])->toBe('300.00')
        ->and($boxes['08'])->toBe('1000.00')
        ->and($boxes['08_tax'])->toBe('200.00')
        ->and($boxes['9B'])->toBe('200.00')
        ->and($boxes['9B_tax'])->toBe('20.00')
        ->and($boxes['16'])->toBe('220.00')
        ->and($boxes['20'])->toBe('100.00')
        ->and($boxes['22'])->toBe('15.00')
        ->and($boxes['23'])->toBe('115.00')
        ->and($boxes['28'])->toBe('105.00')
        ->and($boxes['25'])->toBe('0.00')
        ->and($return['vat_due'])->toBe('105.00');

    $csv = $this->get('/api/v1/general-ledger/fr/ca3/export?from=2026-04-01&to=2026-06-30')->assertOk();
    expect($csv->headers->get('Content-Disposition'))->toContain('ca3-2026-04-01-2026-06-30.csv')
        ->and($csv->getContent())->toContain('08_tax')
        ->and($this->getJson('/api/v1/general-ledger/fr/ca3?from=2026-06-30&to=2026-04-01')->status())->toBe(422);
});

test('the Spanish Modelo 303 and 390 are computed from the ledger', function (): void {
    $tenant = gapTenant('ES');
    gapSale($tenant, '2026-02-10', '1000.00', '210.00', '21', '700', '477');
    gapSale($tenant, '2026-02-11', '200.00', '20.00', '10', '700', '477');
    gapPurchase($tenant, '2026-02-20', '400.00', '84.00', '21', '600', '472');
    gapPurchase($tenant, '2026-03-05', '500.00', '105.00', '21', '600', '472', '477');
    gapSale($tenant, '2026-05-02', '100.00', '21.00', '21', '700', '477');

    $first = $this->getJson('/api/v1/general-ledger/es/modelo-303?from=2026-01-01&to=2026-03-31')->assertOk()->json('data');
    $boxes = gapBoxes($first);

    expect($boxes['07'])->toBe('1000.00')
        ->and($boxes['09'])->toBe('210.00')
        ->and($boxes['04'])->toBe('200.00')
        ->and($boxes['06'])->toBe('20.00')
        ->and($boxes['10'])->toBe('500.00')
        ->and($boxes['11'])->toBe('105.00')
        ->and($boxes['27'])->toBe('335.00')
        ->and($boxes['29'])->toBe('84.00')
        ->and($boxes['37'])->toBe('105.00')
        ->and($boxes['45'])->toBe('189.00')
        ->and($boxes['71'])->toBe('146.00')
        ->and($first['vat_due'])->toBe('146.00');

    $annual = $this->getJson('/api/v1/general-ledger/es/modelo-390?year=2026')->assertOk()->json('data');
    expect($annual['quarters'])->toHaveCount(4)
        ->and(gapBoxes($annual['quarters'][0])['71'])->toBe('146.00')
        ->and(gapBoxes($annual['quarters'][1])['09'])->toBe('21.00')
        ->and(gapBoxes($annual['annual'])['09'])->toBe('231.00')
        ->and($this->get('/api/v1/general-ledger/es/modelo-303/export?from=2026-01-01&to=2026-03-31')->assertOk()->getContent())->toContain('Result of the settlement');
    $this->getJson('/api/v1/general-ledger/es/modelo-390?year=1999')->assertUnprocessable();
});

test('the SII book carries the NIF of a Spanish counterparty and the country and number of a foreign one', function (): void {
    $line = fn (?string $taxId, ?string $country): array => ['protocol_number' => 1, 'posting_date' => '2026-04-15', 'document_date' => '2026-04-12', 'document_number' => 'A-1', 'document_type' => 'invoice', 'partner_name' => 'Cliente', 'partner_tax_id' => $taxId, 'partner_country' => $country, 'taxable_amount' => '100.00', 'vat_rate' => '21', 'vat_amount' => '21.00', 'vat_treatment' => null];
    $exporter = app(SiiVatBookExporter::class);
    $rows = fn (array $register, string $type): array => array_map(fn (string $row): array => str_getcsv($row, ';'), explode("\r\n", trim(ltrim($exporter->csv($register, $type), "\xEF\xBB\xBF"))));

    $spanish = $rows(['lines' => [$line('ES-B12345674', 'ES')], 'totals' => ['taxable' => '100.00', 'tax' => '21.00']], 'sales');
    $foreign = $rows(['lines' => [$line('FR 40 303265045', 'FR')], 'totals' => ['taxable' => '100.00', 'tax' => '21.00']], 'purchases');
    $unknown = $rows(['lines' => [$line(null, null)], 'totals' => ['taxable' => '100.00', 'tax' => '21.00']], 'sales');

    expect($spanish[0])->toContain('ContraparteNIF')
        ->and(array_slice($spanish[1], -4))->toBe(['Cliente', 'B12345674', '', ''])
        ->and(array_slice($foreign[1], -4))->toBe(['Cliente', '', 'FR', '40303265045'])
        ->and(array_slice($unknown[1], -3))->toBe(['', '', '']);
});

test('the German UStVA is computed from the ledger', function (): void {
    $tenant = gapTenant('DE');
    gapSale($tenant, '2026-05-10', '1000.00', '190.00', '19', '8400', '1776');
    gapSale($tenant, '2026-05-11', '100.00', '7.00', '7', '8300', '1771');
    gapSale($tenant, '2026-05-12', '250.00', '0.00', '0', '8125', '1776');
    gapPurchase($tenant, '2026-05-20', '300.00', '57.00', '19', '3400', '1576');

    $return = $this->getJson('/api/v1/general-ledger/de/ustva?from=2026-05-01&to=2026-05-31')->assertOk()->json('data');
    $boxes = gapBoxes($return);

    expect($return['form'])->toBe('UStVA')
        ->and($boxes['81'])->toBe('1000.00')
        ->and($boxes['81_tax'])->toBe('190.00')
        ->and($boxes['86'])->toBe('100.00')
        ->and($boxes['86_tax'])->toBe('7.00')
        ->and($boxes['43'])->toBe('250.00')
        ->and($boxes['66'])->toBe('57.00')
        ->and($boxes['83'])->toBe('140.00')
        ->and($return['vat_due'])->toBe('140.00')
        ->and($this->getJson('/api/v1/general-ledger/de/ustva?from=2026-05-01&to=2026-05-31&previous_credit=5')->status())->toBe(422);

    $this->get('/api/v1/general-ledger/de/ustva/export?from=2026-05-01&to=2026-05-31')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
});

test('the DATEV Buchungsstapel has the documented header and one row per debit and credit pair', function (): void {
    $tenant = gapTenant('DE');
    gapSale($tenant, '2026-05-10', '1000.00', '190.00', '19', '8400', '1776');
    gapEntry($tenant, '2026-05-11', 'manual', [
        ['account_id' => gapAccount($tenant, '4930', 'expense', 'debit')->id, 'debit_amount' => 12.5, 'credit_amount' => 0, 'description' => 'Büro;"Papier"'],
        ['account_id' => gapAccount($tenant, '1200', 'asset', 'debit')->id, 'debit_amount' => 0, 'credit_amount' => 12.5],
    ], 'ER-77');

    $response = $this->get('/api/v1/general-ledger/de/datev/export?from=2026-05-01&to=2026-05-31&consultant_number=1234&client_number=55')->assertOk();
    $text = mb_convert_encoding($response->getContent(), 'UTF-8', 'Windows-1252');
    $lines = explode("\r\n", trim($text));
    $header = str_getcsv($lines[0], ';');

    expect($response->headers->get('Content-Disposition'))->toContain('EXTF_Buchungsstapel_20260501_20260531.csv')
        ->and($header)->toHaveCount(31)
        ->and(array_slice($header, 0, 5))->toBe(['EXTF', '700', '21', 'Buchungsstapel', '13'])
        ->and($header[10])->toBe('1234')
        ->and($header[11])->toBe('55')
        ->and($header[12])->toBe('20260101')
        ->and($header[14])->toBe('20260501')
        ->and($header[15])->toBe('20260531')
        ->and($header[21])->toBe('EUR')
        ->and(str_getcsv($lines[1], ';'))->toHaveCount(14)
        ->and($lines)->toHaveCount(5);

    $rows = collect(array_slice($lines, 2))->map(fn (string $line): array => str_getcsv($line, ';'));
    $sale = $rows->where(6, '1200')->all();

    expect($rows->where(0, '1000,00')->first()[7])->toBe('8400')
        ->and($rows->where(0, '190,00')->first()[7])->toBe('1776')
        ->and($rows->where(0, '190,00')->first()[1])->toBe('S')
        ->and($rows->where(0, '12,50')->first()[6])->toBe('4930')
        ->and($rows->where(0, '12,50')->first()[7])->toBe('1200')
        ->and($rows->where(0, '12,50')->first()[9])->toBe('1105')
        ->and($rows->where(0, '12,50')->first()[10])->toBe('ER-77')
        ->and($rows->where(0, '12,50')->first()[13])->toBe('Büro "Papier"')
        ->and($sale)->toHaveCount(2);

    $this->get('/api/v1/general-ledger/de/datev/export?from=2026-06-01&to=2026-05-01')->assertUnprocessable();
});

test('the DATEV export refuses non-numeric accounts', function (): void {
    $tenant = gapTenant('DE');
    gapSale($tenant, '2026-05-10', '100.00', '19.00', '19', '8400', 'VAT-OUT');

    $this->get('/api/v1/general-ledger/de/datev/export?from=2026-05-01&to=2026-05-31')->assertUnprocessable()->assertJsonValidationErrors('from');
});

test('lines of one account are lettered, appear in the FEC and can be unlettered', function (): void {
    $tenant = gapTenant('FR');
    $receivable = gapAccount($tenant, '411', 'asset', 'debit');
    $invoice = gapSale($tenant, '2026-03-10', '100.00', '20.00', '20', '706', '44571', '411');
    $payment = gapEntry($tenant, '2026-03-20', 'manual', [
        ['account_id' => gapAccount($tenant, '512', 'asset', 'debit')->id, 'debit_amount' => 120, 'credit_amount' => 0],
        ['account_id' => $receivable->id, 'debit_amount' => 0, 'credit_amount' => 120],
    ]);
    $debitLine = JournalLine::withoutGlobalScopes()->where('journal_entry_id', $invoice->id)->where('account_id', $receivable->id)->first();
    $creditLine = JournalLine::withoutGlobalScopes()->where('journal_entry_id', $payment->id)->where('account_id', $receivable->id)->first();
    $otherAccount = JournalLine::withoutGlobalScopes()->where('journal_entry_id', $payment->id)->where('account_id', '!=', $receivable->id)->first();

    $this->postJson('/api/v1/general-ledger/letterings', ['journal_line_ids' => [$debitLine->id]])->assertUnprocessable();
    $this->postJson('/api/v1/general-ledger/letterings', ['journal_line_ids' => [$debitLine->id, $otherAccount->id]])->assertUnprocessable()->assertJsonValidationErrors('journal_line_ids');

    $created = $this->postJson('/api/v1/general-ledger/letterings', ['journal_line_ids' => [$debitLine->id, $creditLine->id], 'lettered_on' => '2026-03-21'])->assertCreated()->json('data');
    expect($created)->toBe(['lettering_code' => 'AA', 'lettered_on' => '2026-03-21', 'lines' => 2]);

    $this->postJson('/api/v1/general-ledger/letterings', ['journal_line_ids' => [$debitLine->id, $creditLine->id]])->assertUnprocessable();
    expect($this->getJson('/api/v1/general-ledger/letterings?account_id='.$receivable->id)->assertOk()->json('data'))->toHaveCount(1);

    $year = FiscalYear::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
    $fec = explode("\r\n", trim($this->get('/api/v1/general-ledger/fec/export?fiscal_year_id='.$year->id)->getContent()));
    $lettered = collect(array_slice($fec, 1))->map(fn (string $line): array => explode("\t", $line))->filter(fn (array $row): bool => $row[13] === 'AA');

    expect($lettered)->toHaveCount(2)
        ->and($lettered->pluck(14)->unique()->all())->toBe(['20260321'])
        ->and($lettered->pluck(4)->unique()->all())->toBe(['411']);

    $this->deleteJson('/api/v1/general-ledger/letterings/AA')->assertNoContent();
    $this->deleteJson('/api/v1/general-ledger/letterings/AA')->assertNotFound();
    expect($this->getJson('/api/v1/general-ledger/letterings')->json('data'))->toBe([]);
});

test('the US sales tax rates of the seed states preload once and are looked up by ZIP code or state', function (): void {
    $tenant = gapTenant('US', 'USD');

    $first = $this->postJson('/api/v1/general-ledger/us-tax/preload')->assertOk()->json('data');
    $again = $this->postJson('/api/v1/general-ledger/us-tax/preload')->assertOk()->json('data');
    expect($first['created'])->toBeGreaterThanOrEqual(8)
        ->and($again)->toBe(['created' => 0, 'skipped' => $first['created']]);

    $this->postJson('/api/v1/general-ledger/us-tax/preload', ['states' => ['ZZ']])->assertUnprocessable();

    $lookup = $this->getJson('/api/v1/general-ledger/us-tax/lookup?zip=90210&date=2026-06-01')->assertOk()->json('data');
    expect($lookup['state'])->toBe('CA')
        ->and($lookup['rate_percent'])->toBe('7.2500')
        ->and($lookup['has_nexus'])->toBeFalse()
        ->and($lookup['lines'])->toHaveCount(1);

    $this->getJson('/api/v1/general-ledger/us-tax/lookup?state=tx')->assertOk()->assertJsonPath('data.rate_percent', '6.2500');
    $this->getJson('/api/v1/general-ledger/us-tax/lookup?state=OR')->assertOk()->assertJsonPath('data.rate_percent', '0.0000');
    $this->getJson('/api/v1/general-ledger/us-tax/lookup?zip=00501')->assertUnprocessable();
    $this->getJson('/api/v1/general-ledger/us-tax/lookup')->assertUnprocessable();
});

test('jurisdictions, rates and nexus can be edited and deleted', function (): void {
    gapTenant('US', 'USD');
    $state = $this->postJson('/api/v1/general-ledger/us-tax/jurisdictions', ['code' => 'NV', 'name' => 'Nevada', 'state' => 'NV', 'level' => 'state'])->assertCreated()->json('data.id');
    $county = $this->postJson('/api/v1/general-ledger/us-tax/jurisdictions', ['code' => 'NV-CL', 'name' => 'Clark', 'state' => 'NV', 'level' => 'county', 'county' => 'Clark'])->assertCreated()->json('data.id');
    $rate = $this->postJson("/api/v1/general-ledger/us-tax/jurisdictions/{$state}/rates", ['rate_percent' => 4.6, 'effective_from' => '2020-01-01'])->assertCreated()->json('data.id');

    $this->putJson("/api/v1/general-ledger/us-tax/jurisdictions/{$state}", ['name' => 'State of Nevada', 'is_active' => false])->assertOk()->assertJsonPath('data.name', 'State of Nevada');
    $this->putJson("/api/v1/general-ledger/us-tax/jurisdictions/{$county}", ['county' => ''])->assertUnprocessable()->assertJsonValidationErrors('county');
    $this->putJson("/api/v1/general-ledger/us-tax/jurisdictions/{$county}", ['code' => 'NV'])->assertUnprocessable()->assertJsonValidationErrors('code');

    $this->putJson("/api/v1/general-ledger/us-tax/rates/{$rate}", ['rate_percent' => 6.85, 'applies_to_shipping' => true])->assertOk()->assertJsonPath('data.rate_percent', '6.8500');
    $this->putJson("/api/v1/general-ledger/us-tax/rates/{$rate}", ['effective_to' => '2019-01-01'])->assertUnprocessable()->assertJsonValidationErrors('effective_to');

    $nexus = $this->postJson('/api/v1/general-ledger/us-tax/nexus', ['state' => 'NV', 'nexus_type' => 'economic', 'established_on' => '2026-01-01'])->assertCreated()->json('data.id');
    $this->putJson("/api/v1/general-ledger/us-tax/nexus/{$nexus}", ['ended_on' => '2026-12-31', 'registration_number' => 'NV-1'])->assertOk()->assertJsonPath('data.registration_number', 'NV-1');
    $this->putJson("/api/v1/general-ledger/us-tax/nexus/{$nexus}", ['ended_on' => '2025-12-31'])->assertUnprocessable();

    $this->deleteJson("/api/v1/general-ledger/us-tax/rates/{$rate}")->assertNoContent();
    $this->deleteJson("/api/v1/general-ledger/us-tax/rates/{$rate}")->assertNotFound();
    $this->deleteJson("/api/v1/general-ledger/us-tax/nexus/{$nexus}")->assertNoContent();
    $this->deleteJson("/api/v1/general-ledger/us-tax/jurisdictions/{$state}")->assertNoContent();

    expect($this->getJson('/api/v1/general-ledger/us-tax/nexus')->json('data'))->toBe([])
        ->and($this->getJson('/api/v1/general-ledger/us-tax/jurisdictions')->json('data'))->toHaveCount(1);
});

test('an exemption certificate removes the tax of its state until it expires', function (): void {
    $tenant = gapTenant('US', 'USD');
    $customer = Contact::create(['tenant_id' => (string) $tenant->id, 'company_name' => 'Reseller Inc', 'type' => 'customer', 'country' => 'US']);
    $other = Contact::create(['tenant_id' => (string) $tenant->id, 'company_name' => 'Retail Inc', 'type' => 'customer', 'country' => 'US']);
    $state = $this->postJson('/api/v1/general-ledger/us-tax/jurisdictions', ['code' => 'CA', 'name' => 'California', 'state' => 'CA', 'level' => 'state'])->assertCreated()->json('data.id');
    $county = $this->postJson('/api/v1/general-ledger/us-tax/jurisdictions', ['code' => 'CA-LA', 'name' => 'Los Angeles County', 'state' => 'CA', 'level' => 'county', 'county' => 'Los Angeles'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/general-ledger/us-tax/jurisdictions/{$state}/rates", ['rate_percent' => 7.25, 'effective_from' => '2020-01-01'])->assertCreated();
    $this->postJson("/api/v1/general-ledger/us-tax/jurisdictions/{$county}/rates", ['rate_percent' => 2.25, 'effective_from' => '2020-01-01'])->assertCreated();
    $this->postJson('/api/v1/general-ledger/us-tax/nexus', ['state' => 'CA', 'nexus_type' => 'physical', 'established_on' => '2020-01-01'])->assertCreated();

    $payload = ['state' => 'CA', 'date' => '2026-05-01', 'taxable_amount' => 100, 'county' => 'Los Angeles', 'customer_id' => $customer->id];
    $calculate = fn (array $overrides = []): array => $this->postJson('/api/v1/general-ledger/us-tax/calculate', [...$payload, ...$overrides])->assertOk()->json('data');

    expect($calculate()['tax_total'])->toBe('9.50');

    $countyOnly = $this->postJson('/api/v1/general-ledger/us-tax/exemption-certificates', [
        'contact_id' => $customer->id, 'state' => 'ca', 'jurisdiction_id' => $county, 'certificate_number' => 'CERT-1', 'reason' => 'resale', 'issued_on' => '2026-01-01', 'expires_on' => '2026-12-31',
    ])->assertCreated()->json('data');
    $partial = $calculate();
    expect($partial['tax_total'])->toBe('7.25')
        ->and($partial['exempt_tax'])->toBe('2.25')
        ->and($partial['exemption_certificate_id'])->toBe($countyOnly['id'])
        ->and(collect($partial['lines'])->pluck('exempt', 'code')->all())->toBe(['CA' => false, 'CA-LA' => true])
        ->and($calculate(['customer_id' => $other->id])['tax_total'])->toBe('9.50');

    $whole = $this->postJson('/api/v1/general-ledger/us-tax/exemption-certificates', [
        'contact_id' => $customer->id, 'state' => 'CA', 'certificate_number' => 'CERT-2', 'issued_on' => '2026-01-01', 'expires_on' => '2026-06-30',
    ])->assertCreated()->json('data');
    $full = $calculate();
    expect($full['tax_total'])->toBe('0.00')
        ->and($full['exempt_tax'])->toBe('9.50')
        ->and($full['reason'])->toBe('exempt')
        ->and($calculate(['date' => '2026-07-01'])['tax_total'])->toBe('7.25')
        ->and($calculate(['date' => '2025-12-31'])['has_nexus'])->toBeTrue();

    $this->putJson("/api/v1/general-ledger/us-tax/exemption-certificates/{$whole['id']}", ['is_active' => false])->assertOk();
    expect($calculate()['tax_total'])->toBe('7.25');
    $this->putJson("/api/v1/general-ledger/us-tax/exemption-certificates/{$whole['id']}", ['expires_on' => '2025-01-01'])->assertUnprocessable()->assertJsonValidationErrors('expires_on');
    $this->postJson('/api/v1/general-ledger/us-tax/exemption-certificates', ['contact_id' => $customer->id, 'state' => 'NY', 'jurisdiction_id' => $county, 'certificate_number' => 'X', 'issued_on' => '2026-01-01'])->assertUnprocessable()->assertJsonValidationErrors('jurisdiction_id');
    expect($this->getJson('/api/v1/general-ledger/us-tax/exemption-certificates?contact_id='.$customer->id)->json('data'))->toHaveCount(2);

    $this->deleteJson("/api/v1/general-ledger/us-tax/exemption-certificates/{$countyOnly['id']}")->assertNoContent();
    $this->deleteJson("/api/v1/general-ledger/us-tax/exemption-certificates/{$countyOnly['id']}")->assertNotFound();
});

test('1099 recipients and payments can be edited and deleted, and a payment links to a supplier payment once', function (): void {
    $tenant = gapTenant('US', 'USD');
    $tin = (string) random_int(100000000, 999999999);
    $newTin = (string) random_int(100000000, 999999999);
    $recipient = $this->postJson('/api/v1/general-ledger/us-1099/recipients', ['name' => 'Jane', 'tin_type' => 'ssn', 'tin' => $tin])->assertCreated()->json('data.id');

    $updated = $this->putJson("/api/v1/general-ledger/us-1099/recipients/{$recipient}", ['name' => 'Jane Doe', 'tin' => $newTin, 'state' => 'ny', 'w9_received_on' => '2026-01-05'])->assertOk()->json('data');
    expect($updated['name'])->toBe('Jane Doe')
        ->and($updated['tin_last4'])->toBe(substr($newTin, -4))
        ->and($updated['state'])->toBe('NY')
        ->and($updated)->not->toHaveKey('tin');
    $this->putJson("/api/v1/general-ledger/us-1099/recipients/{$recipient}", ['tin' => '12'])->assertUnprocessable();
    $this->putJson("/api/v1/general-ledger/us-1099/recipients/{$recipient}", ['name' => 'Jane Doe'])->assertOk();
    expect(DB::table('us_1099_recipients')->where('id', $recipient)->value('tin_last4'))->toBe(substr($newTin, -4));

    $supplier = Contact::create(['tenant_id' => (string) $tenant->id, 'company_name' => 'Jane Doe LLC', 'type' => 'supplier', 'country' => 'US']);
    $invoice = PassiveInvoice::create(['tenant_id' => $tenant->id, 'supplier_id' => $supplier->id, 'invoice_number' => 'JD-1', 'date' => '2026-02-01', 'total_amount' => 2500, 'currency' => 'USD', 'document_type' => 'TD01']);
    $supplierPayment = SupplierPayment::create(['tenant_id' => $tenant->id, 'passive_invoice_id' => $invoice->id, 'amount' => 2500, 'currency' => 'USD', 'paid_at' => '2026-02-10', 'type' => 'payment', 'reference' => 'WIRE-1']);
    $fee = SupplierPayment::create(['tenant_id' => $tenant->id, 'passive_invoice_id' => $invoice->id, 'amount' => 15, 'currency' => 'USD', 'paid_at' => '2026-02-10', 'type' => 'bank_fee']);
    $euro = SupplierPayment::create(['tenant_id' => $tenant->id, 'passive_invoice_id' => $invoice->id, 'amount' => 100, 'currency' => 'EUR', 'paid_at' => '2026-02-11', 'type' => 'payment']);

    $available = $this->getJson('/api/v1/general-ledger/us-1099/supplier-payments?year=2026')->assertOk()->json('data');
    expect(collect($available)->pluck('id')->all())->toContain($supplierPayment->id)
        ->and(collect($available)->firstWhere('id', $supplierPayment->id)['invoice_number'])->toBe('JD-1');

    $this->postJson('/api/v1/general-ledger/us-1099/payments', ['recipient_id' => $recipient, 'form_type' => '1099-NEC', 'box' => '1', 'supplier_payment_id' => $fee->id])->assertUnprocessable()->assertJsonValidationErrors('supplier_payment_id');
    $this->postJson('/api/v1/general-ledger/us-1099/payments', ['recipient_id' => $recipient, 'form_type' => '1099-NEC', 'box' => '1', 'supplier_payment_id' => $euro->id])->assertUnprocessable()->assertJsonValidationErrors('supplier_payment_id');
    $this->postJson('/api/v1/general-ledger/us-1099/payments', ['recipient_id' => $recipient, 'form_type' => '1099-NEC', 'box' => '1'])->assertUnprocessable()->assertJsonValidationErrors(['paid_on', 'amount']);

    $payment = $this->postJson('/api/v1/general-ledger/us-1099/payments', ['recipient_id' => $recipient, 'form_type' => '1099-NEC', 'box' => '1', 'supplier_payment_id' => $supplierPayment->id])->assertCreated()->json('data');
    expect($payment['supplier_payment_id'])->toBe($supplierPayment->id)
        ->and($payment['paid_on'])->toStartWith('2026-02-10')
        ->and($payment['amount'])->toBe('2500.00')
        ->and($payment['reference'])->toBe('WIRE-1');

    $this->postJson('/api/v1/general-ledger/us-1099/payments', ['recipient_id' => $recipient, 'form_type' => '1099-NEC', 'box' => '1', 'supplier_payment_id' => $supplierPayment->id])->assertUnprocessable()->assertJsonValidationErrors('supplier_payment_id');
    expect(collect($this->getJson('/api/v1/general-ledger/us-1099/supplier-payments?year=2026')->json('data'))->pluck('id')->all())->not->toContain($supplierPayment->id);

    $this->putJson("/api/v1/general-ledger/us-1099/payments/{$payment['id']}", ['amount' => 2400, 'box' => '4'])->assertOk()->assertJsonPath('data.amount', '2400.00');
    $this->putJson("/api/v1/general-ledger/us-1099/payments/{$payment['id']}", ['box' => '10'])->assertUnprocessable()->assertJsonValidationErrors('box');
    expect($this->getJson("/api/v1/general-ledger/us-1099/payments?year=2026&recipient_id={$recipient}")->json('data'))->toHaveCount(1);

    $this->deleteJson("/api/v1/general-ledger/us-1099/payments/{$payment['id']}")->assertNoContent();
    expect(collect($this->getJson('/api/v1/general-ledger/us-1099/supplier-payments?year=2026')->json('data'))->pluck('id')->all())->toContain($supplierPayment->id);

    $this->postJson('/api/v1/general-ledger/us-1099/payments', ['recipient_id' => $recipient, 'form_type' => '1099-NEC', 'box' => '1', 'paid_on' => '2026-03-01', 'amount' => 50])->assertCreated();
    $this->deleteJson("/api/v1/general-ledger/us-1099/recipients/{$recipient}")->assertNoContent();
    expect($this->getJson('/api/v1/general-ledger/us-1099/recipients')->json('data'))->toBe([])
        ->and($this->getJson('/api/v1/general-ledger/us-1099/payments')->json('data'))->toBe([])
        ->and(JournalEntry::withoutGlobalScopes()->count())->toBe(0);
});

test('the localization endpoints need the accounting role', function (): void {
    $viewer = gapTenant('DE', 'EUR', 'accountant_readonly');

    $this->getJson('/api/v1/general-ledger/de/ustva?from=2026-01-01&to=2026-03-31')->assertOk();
    $this->postJson('/api/v1/general-ledger/us-tax/preload')->assertForbidden();
    $this->postJson('/api/v1/general-ledger/letterings', ['journal_line_ids' => [(string) Str::uuid(), (string) Str::uuid()]])->assertForbidden();
    $this->postJson('/api/v1/general-ledger/us-tax/exemption-certificates', [])->assertForbidden();
    expect($viewer->country)->toBe('DE');
});
