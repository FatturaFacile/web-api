<?php

declare(strict_types=1);

use App\Contracts\PdfGenerator;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PassiveInvoice;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\CustomsAndPemOriginSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Customs\Models\CustomsDeclaration;
use Modules\Customs\Models\CustomsSettings;
use Modules\Customs\Services\ExportProofService;
use Modules\Customs\Support\Mrn;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\GeneralLedger\Models\JournalLine;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

/**
 * A PDF generator that returns the rendered HTML, so that a test can read the document.
 */
function customsHtmlPdf(): void
{
    app()->instance(PdfGenerator::class, new class implements PdfGenerator
    {
        public function render(string $view, array $data = []): string
        {
            return view($view, $data)->render();
        }
    });
}

function customsExportPayload(Contact $consignee, array $overrides = []): array
{
    return customsImportPayload($consignee, ['declaration_type' => 'export', 'destination_country' => 'DE', 'dispatch_country' => null, ...$overrides]);
}

function customsPreferentialInvoice(Contact $customer): Invoice
{
    $invoice = Invoice::create([
        'tenant_id' => $customer->tenant_id,
        'contact_id' => $customer->id,
        'invoice_number' => 'EXP-'.Str::random(5),
        'date' => '2026-11-02',
        'currency' => 'CHF',
    ]);
    InvoiceItem::create(['invoice_id' => $invoice->id, 'description' => 'Goods', 'quantity' => 1, 'unit_price' => 500, 'line_total' => 500, 'origin_type' => 'preferential']);

    return $invoice->fresh();
}

beforeEach(function (): void {
    Storage::fake('private');
    $this->tenant = customsTenant();
    $this->user = customsMember($this->tenant);
    $this->consignee = customsContact($this->tenant, 'Importatrice SRL', 'IT');
    customsSettings($this->tenant);
    customsApi($this->user, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('an MRN is validated as a Union or Swiss reference', function (): void {
    expect(Mrn::isUnion('26IT0000000000001A'))->toBeTrue()
        ->and(Mrn::isUnion('26it0000000000001a'))->toBeTrue()
        ->and(Mrn::isUnion('26IT0000000000001'))->toBeFalse()
        ->and(Mrn::isUnion('ITIT0000000000001A'))->toBeFalse()
        ->and(Mrn::isValid('26CHEI000000000001XX'))->toBeTrue()
        ->and(Mrn::isValid('SHORT'))->toBeFalse()
        ->and(Mrn::isUnion(Mrn::simulated('IT')))->toBeTrue();
});

test('an MRN is assigned only when well formed and unused', function (): void {
    $first = $this->postJson('/api/v1/customs/declarations', customsExportPayload($this->consignee))->json('data.id');
    $second = $this->postJson('/api/v1/customs/declarations', customsExportPayload($this->consignee))->json('data.id');

    $this->postJson("/api/v1/customs/declarations/{$first}/mrn", ['mrn' => 'bad'])->assertUnprocessable()->assertJsonValidationErrors('mrn');
    $this->postJson("/api/v1/customs/declarations/{$first}/mrn", ['mrn' => '26it0000000000001a'])->assertOk()->assertJsonPath('data.mrn', '26IT0000000000001A');
    $this->postJson("/api/v1/customs/declarations/{$second}/mrn", ['mrn' => '26IT0000000000001A'])->assertUnprocessable()->assertJsonValidationErrors('mrn');
});

test('the AIDA sandbox channel builds a DAU payload and answers with a Union MRN', function (): void {
    customsSettings($this->tenant, ['channel' => 'aida']);
    $id = $this->postJson('/api/v1/customs/declarations', customsExportPayload($this->consignee))->json('data.id');
    $this->postJson("/api/v1/customs/declarations/{$id}/validate")->assertOk()->assertJsonPath('data.errors', []);

    $payload = json_decode($this->get("/api/v1/customs/declarations/{$id}/message")->assertOk()->getContent(), true);
    expect($payload['format'])->toBe('fattura-facile/aida-sandbox/1')
        ->and($payload['box_1_declaration']['flow'])->toBe('EX')
        ->and($payload['box_17_destination_country'])->toBe('DE')
        ->and($payload['items'])->toHaveCount(2)
        ->and($payload['items'][0]['box_33_commodity_code'])->toBe('2201.1000');

    $response = $this->postJson("/api/v1/customs/declarations/{$id}/submit")->assertOk()
        ->assertJsonPath('data.status', 'accepted')
        ->assertJsonPath('data.gateway', 'aida')
        ->assertJsonPath('data.customs_regime', 'aida');

    expect(Mrn::isUnion((string) $response->json('data.mrn')))->toBeTrue()
        ->and($response->json('data.mrn'))->toContain('IT');
});

test('the Passar sandbox channel builds an XML payload and records the regime', function (): void {
    customsSettings($this->tenant, ['channel' => 'passar']);
    $id = $this->postJson('/api/v1/customs/declarations', customsExportPayload($this->consignee, ['transit_document_type' => 'T1', 'border_crossing' => 'Chiasso']))->json('data.id');
    $this->postJson("/api/v1/customs/declarations/{$id}/validate")->assertOk();

    $xml = $this->get("/api/v1/customs/declarations/{$id}/message")->assertOk()->getContent();
    expect($xml)->toContain('<passarDeclaration', '<transitDocument>T1</transitDocument>', '<borderCrossing>Chiasso</borderCrossing>', '<tariffNumber>2201.1000</tariffNumber>')
        ->and(simplexml_load_string($xml))->not->toBeFalse();

    $this->postJson("/api/v1/customs/declarations/{$id}/submit")->assertOk()
        ->assertJsonPath('data.customs_regime', 'passar')
        ->assertJsonPath('data.status', 'accepted');
});

test('a transit declaration prints a bilingual tracking sheet and is discharged once', function (): void {
    customsHtmlPdf();
    $id = $this->postJson('/api/v1/customs/declarations', customsExportPayload($this->consignee, [
        'transit_document_type' => 'T2',
        'transit_guarantee_reference' => 'GUA-123',
        'transit_departure_office' => 'CH000001',
        'transit_destination_office' => 'IT000002',
        'transit_deadline_on' => '2020-01-01',
    ]))->assertCreated()->json('data.id');

    $sheet = $this->get("/api/v1/customs/declarations/{$id}/transit-sheet?language=it_de")->assertOk()->getContent();
    expect($sheet)->toContain('Foglio di controllo transito', 'Versandkontrollblatt', 'GUA-123', 'CH000001', 'Scaduto / Überfällig');

    expect($this->getJson('/api/v1/customs/trade-compliance/dashboard')->assertOk()->json('data.transit_overdue'))->toBe(1);

    $this->postJson("/api/v1/customs/declarations/{$id}/transit-discharge")->assertOk();
    $sheet = $this->get("/api/v1/customs/declarations/{$id}/transit-sheet")->getContent();
    expect($sheet)->toContain('Svincolato / Discharged')
        ->and($this->getJson('/api/v1/customs/trade-compliance/dashboard')->json('data.transit_overdue'))->toBe(0);

    $plain = $this->postJson('/api/v1/customs/declarations', customsExportPayload($this->consignee))->json('data.id');
    $this->get("/api/v1/customs/declarations/{$plain}/transit-sheet")->assertUnprocessable();
});

test('the pro-forma invoice is bilingual, carries tariff data and the preferential origin clause', function (): void {
    $this->seed(CustomsAndPemOriginSeeder::class);
    customsHtmlPdf();
    $invoice = customsPreferentialInvoice($this->consignee);
    $id = $this->postJson('/api/v1/customs/declarations', customsExportPayload($this->consignee))->json('data.id');
    $this->postJson("/api/v1/customs/declarations/{$id}/invoices", ['invoice_ids' => [$invoice->id]])->assertOk();

    $html = $this->get("/api/v1/customs/declarations/{$id}/pro-forma?language=it_de")->assertOk()->getContent();

    expect($html)->toContain('Fattura commerciale / pro-forma per la dogana', 'Handels- / Proforma-Rechnung', 'CHE123456789', 'DAP', '2201.1000', '2204.2100', $invoice->invoice_number)
        ->and($html)->toContain('125.500', '110.250', '1300.50', 'Dichiarazione di origine preferenziale', 'Präferenzursprungserklärung');

    $this->get("/api/v1/customs/declarations/{$id}/pro-forma?language=it_es")->assertUnprocessable();
});

test('the pro-forma has no origin clause when no invoice is linked', function (): void {
    customsHtmlPdf();
    $id = $this->postJson('/api/v1/customs/declarations', customsExportPayload($this->consignee))->json('data.id');

    expect($this->get("/api/v1/customs/declarations/{$id}/pro-forma")->assertOk()->getContent())->not->toContain('Dichiarazione di origine preferenziale');
});

test('an accepted export lacks its proof until the export decision is archived, then it is matched', function (): void {
    $invoice = customsPreferentialInvoice($this->consignee);
    $id = $this->postJson('/api/v1/customs/declarations', customsExportPayload($this->consignee, ['trader_reference' => $invoice->invoice_number]))->json('data.id');
    $this->postJson("/api/v1/customs/declarations/{$id}/validate")->assertOk();
    $this->postJson("/api/v1/customs/declarations/{$id}/submit")->assertOk();
    $this->postJson("/api/v1/customs/declarations/{$id}/invoices/auto-link")->assertOk()->assertJsonPath('data.linked', 1);
    $this->postJson("/api/v1/customs/declarations/{$id}/invoices/auto-link")->assertOk()->assertJsonPath('data.linked', 0);

    $missing = $this->getJson('/api/v1/customs/trade-compliance/dashboard')->assertOk()->json('data.missing_evv');
    expect($missing)->toHaveCount(1)
        ->and($missing[0]['invoices'][0]['invoice_number'])->toBe($invoice->invoice_number);
    $this->getJson("/api/v1/customs/declarations/{$id}/export-proof")->assertJsonPath('data.has_proof', false);

    $this->getJson("/api/v1/customs/declarations/{$id}")->assertJsonPath('data.workflow_stage', 'cleared');
    expect($this->getJson('/api/v1/customs/trade-compliance/dashboard')->json('data.by_stage.cleared'))->toBe(1);

    $this->postJson("/api/v1/customs/declarations/{$id}/mrn", ['mrn' => '26CHEI000000000001'])->assertOk();
    $decision = customsArchive($this->tenant, customsDutyDecisionXml(type: 'taxationDecisionExport'));

    expect($decision->declaration_id)->toBe($id);
    $this->getJson("/api/v1/customs/declarations/{$id}")->assertJsonPath('data.workflow_stage', 'archived_evv');
    $this->getJson("/api/v1/customs/declarations/{$id}/export-proof")->assertJsonPath('data.has_proof', true)->assertJsonPath('data.decision.mrn', '26CHEI000000000001');
    expect($this->getJson('/api/v1/customs/trade-compliance/dashboard')->json('data.missing_evv'))->toBe([])
        ->and(app(ExportProofService::class)->hasProof($invoice))->toBeTrue();
});

test('a declaration cannot be linked to an invoice of another company', function (): void {
    $other = customsTenant();
    $foreign = customsPreferentialInvoice(customsContact($other));
    app(TenantContext::class)->setTenant($this->tenant);
    $id = $this->postJson('/api/v1/customs/declarations', customsExportPayload($this->consignee))->json('data.id');

    $this->postJson("/api/v1/customs/declarations/{$id}/invoices", ['invoice_ids' => [$foreign->id]])->assertUnprocessable();
});

test('an import customs bill splits duty from import VAT, reconciles with the broker invoice and posts', function (): void {
    $this->travelTo('2026-11-07 10:00:00');
    app(OpenFiscalYearAction::class)->execute($this->tenant->id, 2026);
    $accounts = [
        'duty' => customsLedgerAccount($this->tenant, '4200', 'expense'),
        'payable' => customsLedgerAccount($this->tenant, '2230', 'liability'),
        'vat' => customsLedgerAccount($this->tenant, '1170', 'asset'),
    ];
    CustomsSettings::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->update([
        'duty_expense_account_id' => $accounts['duty'],
        'customs_payable_account_id' => $accounts['payable'],
    ]);
    $broker = customsContact($this->tenant, 'Spedizioniere SA', 'CH');
    $passive = new PassiveInvoice;
    $passive->forceFill(['tenant_id' => $this->tenant->id, 'supplier_id' => $broker->id, 'invoice_number' => 'BRK-1', 'date' => '2026-11-06', 'total_amount' => '219.10', 'currency' => 'CHF'])->save();

    $created = $this->postJson('/api/v1/customs/import-bills', [
        'bill_number' => 'BD-2026-77', 'bill_date' => '2026-11-05', 'currency' => 'chf', 'customs_value' => '1000.00', 'duty_amount' => '100.00',
        'ancillary_costs' => '0', 'vat_rate' => '8.10', 'passive_invoice_id' => $passive->id,
    ])->assertCreated()->assertJsonPath('data.import_vat_amount', '89.10')->assertJsonPath('data.currency', 'CHF')->assertJsonPath('data.status', 'draft');
    $id = $created->json('data.id');

    $this->postJson('/api/v1/customs/import-bills', ['bill_number' => 'BD-2026-77', 'bill_date' => '2026-11-05', 'currency' => 'CHF', 'customs_value' => '1', 'duty_amount' => '0', 'vat_rate' => '8.1'])
        ->assertUnprocessable()->assertJsonValidationErrors('bill_number');

    $this->getJson("/api/v1/customs/import-bills/{$id}/reconciliation")->assertOk()
        ->assertJsonPath('data.bill_total', '189.10')
        ->assertJsonPath('data.supplier_total', '219.10')
        ->assertJsonPath('data.remainder', '30.00')
        ->assertJsonPath('data.matches', false);

    $accountant = customsMember($this->tenant, 'accountant');
    customsApi($accountant, $this->tenant)->postJson("/api/v1/customs/import-bills/{$id}/post")->assertOk()->assertJsonPath('data.status', 'posted');
    customsApi($accountant, $this->tenant)->postJson("/api/v1/customs/import-bills/{$id}/post")->assertOk();

    $sum = static fn (string $account, string $column): string => number_format((float) JournalLine::withoutGlobalScopes()->where('account_id', $account)->sum($column), 2, '.', '');
    expect($sum($accounts['duty'], 'debit_amount'))->toBe('100.00')
        ->and($sum($accounts['payable'], 'credit_amount'))->toBe('189.10');
})->skip(fn () => ! class_exists(OpenFiscalYearAction::class), 'General Ledger module not available');

test('the product master data keeps the Swiss HS code, weights and the preferential flag', function (): void {
    expect(Schema::hasColumns('products', ['swiss_hs_code', 'supplementary_unit', 'customs_duty_rate', 'net_weight_kg', 'gross_weight_kg', 'packages_count', 'preferential_origin_eligible']))->toBeTrue()
        ->and(Schema::hasColumns('customs_goods_declarations', ['customs_regime', 'transit_document_type', 'transit_guarantee_reference', 'transit_deadline_on']))->toBeTrue();
});

test('customs declarations of one company are not counted on the dashboard of another', function (): void {
    $this->postJson('/api/v1/customs/declarations', customsExportPayload($this->consignee))->assertCreated();
    $other = customsTenant();
    customsSettings($other);
    customsApi(customsMember($other), $other);

    expect($this->getJson('/api/v1/customs/trade-compliance/dashboard')->assertOk()->json('data.by_direction'))->toBe([])
        ->and(CustomsDeclaration::withoutGlobalScopes()->count())->toBe(1);
});

test('the pro-forma can be rendered in French and falls back to the incoterm of the linked invoice', function (): void {
    customsHtmlPdf();
    $invoice = customsPreferentialInvoice($this->consignee);
    $invoice->update(['incoterm' => 'FCA', 'incoterm_place' => 'Lugano']);
    $id = $this->postJson('/api/v1/customs/declarations', customsExportPayload($this->consignee, ['incoterms' => null]))->json('data.id');
    $this->postJson("/api/v1/customs/declarations/{$id}/invoices", ['invoice_ids' => [$invoice->id]])->assertOk();

    $html = $this->get("/api/v1/customs/declarations/{$id}/pro-forma?language=it_fr")->assertOk()->getContent();

    expect($html)->toContain('Facture commerciale / pro forma pour la douane', 'FCA Lugano', 'ch_export_art_23_ltva');
});

test('the packing list shows packages, weights, incoterm and MRN in the chosen languages', function (): void {
    customsHtmlPdf();
    $id = $this->postJson('/api/v1/customs/declarations', customsExportPayload($this->consignee))->json('data.id');
    $this->postJson("/api/v1/customs/declarations/{$id}/mrn", ['mrn' => '26CHEI000000000001'])->assertOk();

    $html = $this->get("/api/v1/customs/declarations/{$id}/packing-list?language=it_de")->assertOk()->getContent();

    expect($html)->toContain('Packliste', 'Packing list / lista di colli', '26CHEI000000000001', 'DAP', 'Totale / Total');
    $this->get("/api/v1/customs/declarations/{$id}/packing-list?language=it_fr")->assertOk();
    $this->get("/api/v1/customs/declarations/{$id}/packing-list?language=xx")->assertUnprocessable();
});

test('a packing list of a declaration of another company is not found', function (): void {
    customsHtmlPdf();
    $id = $this->postJson('/api/v1/customs/declarations', customsExportPayload($this->consignee))->json('data.id');
    $other = customsTenant();
    customsApi(customsMember($other), $other);

    $this->get("/api/v1/customs/declarations/{$id}/packing-list")->assertNotFound();
});
