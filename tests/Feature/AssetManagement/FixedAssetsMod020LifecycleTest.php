<?php

declare(strict_types=1);

namespace Tests\Feature\AssetManagement;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\PassiveInvoiceLine;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\FiscalDepreciationRateSeeder;
use Database\Seeders\Translations\FixedAssetsFrontendTranslations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\AssetManagement\Models\AssetBook;
use Modules\AssetManagement\Models\AssetCategory;
use Modules\AssetManagement\Models\DepreciationRun;
use Modules\AssetManagement\Models\FiscalDepreciationRate;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\GeneralLedger\Actions\PostPassiveInvoiceAction;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\JournalEntry;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();

    $this->seed(FiscalDepreciationRateSeeder::class);

    $this->tenant = Tenant::create([
        'name' => 'Cespiti Mod020',
        'legal_name' => 'Cespiti Mod020 SA',
        'vat_number' => 'IT12345678901',
        'uid_number' => 'CHE-123.456.789',
        'country' => 'CH',
        'default_currency' => 'CHF',
    ]);
    $this->otherTenant = Tenant::create([
        'name' => 'Altra Azienda',
        'legal_name' => 'Altra Azienda Srl',
        'vat_number' => 'IT99999999999',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    app(TenantContext::class)->setTenant($this->tenant);
    fiscalCalendar($this->tenant, (int) date('Y'));
    fiscalCalendar($this->otherTenant, (int) date('Y'));

    $this->admin = User::create([
        'name' => 'Asset Admin',
        'email' => Str::uuid().'@example.test',
        'password' => bcrypt(Str::password(16)),
    ]);
    $this->admin->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin', 'permissions' => json_encode([])]);

    $this->member = User::create([
        'name' => 'Read Only Member',
        'email' => Str::uuid().'@example.test',
        'password' => bcrypt(Str::password(16)),
    ]);
    $this->member->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'member', 'permissions' => json_encode([])]);

    Sanctum::actingAs($this->admin);
    $this->withHeader('X-Tenant-Id', (string) $this->tenant->id);
});

function mod020Category(Tenant $tenant, array $overrides = []): AssetCategory
{
    return AssetCategory::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'code' => 'CAT-'.strtoupper(Str::random(4)),
        'name' => 'Industrial Machinery',
        'asset_type' => 'tangible',
        'depreciation_method' => 'straight_line',
        'useful_life_years' => 5,
        'useful_life_months' => 60,
        'statutory_rate_percent' => '20.00',
        'salvage_value_percent' => '0.00',
        'pro_rata_convention' => 'full',
        'gl_asset_account_code' => '1600',
        'gl_accumulated_depreciation_code' => '1650',
        'gl_depreciation_expense_code' => '6400',
        'gl_impairment_account_code' => '6450',
        'gl_revaluation_reserve_code' => '3300',
        'gl_disposal_gain_loss_code' => '7500',
        'gl_clearing_account_code' => '2150',
        'is_depreciable' => true,
        'is_active' => true,
        ...$overrides,
    ]);
}

test('official CH AFC and IT DM 1988 fiscal depreciation rates are queryable and localized across 5 locales', function (): void {
    $ch = $this->getJson('/api/v1/eam/fiscal-rates?jurisdiction=CH&locale=de-CH')->assertOk();
    expect($ch->json('data'))->not->toBeEmpty()
        ->and(collect($ch->json('data'))->pluck('regime_code')->unique()->all())->toBe(['CH_AFC_A1995']);

    $it = $this->getJson('/api/v1/eam/fiscal-rates?jurisdiction=IT&sector_code=GR01')->assertOk();
    expect($it->json('data'))->not->toBeEmpty()
        ->and(collect($it->json('data'))->firstWhere('category_code', 'IT-GR01-MACH-SPEC')['first_year_half_rate'])->toBeTrue();
});

test('generates progressive asset matricola and compares parallel Civil vs CH AFC and IT DM 1988 schedules', function (): void {
    $category = mod020Category($this->tenant);
    $chRate = FiscalDepreciationRate::where('category_code', 'CH-MACH-GEN')->firstOrFail();
    $itRate = FiscalDepreciationRate::where('category_code', 'IT-GR01-MACH-SPEC')->firstOrFail();

    $a1 = $this->postJson('/api/v1/eam/assets', [
        'name' => 'Swiss CNC Lathe',
        'category_id' => $category->id,
        'acquisition_cost' => '10000.00',
        'useful_life_years' => 5,
        'pro_rata_convention' => 'full',
        'fiscal_jurisdiction' => 'CH',
        'fiscal_rate_id' => $chRate->id,
        'in_service_date' => date('Y').'-01-10',
    ])->assertCreated();

    $a2 = $this->postJson('/api/v1/eam/assets', [
        'name' => 'Italian 4.0 CNC Mill',
        'category_id' => $category->id,
        'acquisition_cost' => '20000.00',
        'useful_life_years' => 5,
        'pro_rata_convention' => 'full',
        'fiscal_jurisdiction' => 'IT',
        'fiscal_rate_id' => $itRate->id,
        'incentive_regime' => 'transition_4_0_tangible',
        'incentive_rate_percent' => '20.0000',
        'incentive_installment_years' => 3,
        'incentive_interconnection_date' => date('Y').'-03-01',
        'in_service_date' => date('Y').'-03-01',
    ])->assertCreated();

    expect($a1->json('data.asset_number'))->toBe('AST-'.date('Y').'-00001')
        ->and($a2->json('data.asset_number'))->toBe('AST-'.date('Y').'-00002')
        ->and($a2->json('data.incentive_tax_credit_total'))->toBe('4000.00');

    $id1 = (string) $a1->json('data.id');
    $this->postJson("/api/v1/eam/assets/{$id1}/capitalize", ['capitalization_date' => date('Y').'-01-10'])->assertOk();

    // CH AFC 30% declining = 3,000 vs Civil 20% linear = 2,000 -> temporary difference = +1,000
    $parallel1 = $this->getJson("/api/v1/eam/assets/{$id1}/parallel-schedules?period_type=yearly&periods=5")->assertOk();
    expect($parallel1->json('data.rows.0.civil_depreciation'))->toBe('2000.00')
        ->and($parallel1->json('data.rows.0.fiscal_depreciation'))->toBe('3000.00')
        ->and($parallel1->json('data.rows.0.temporary_difference'))->toBe('1000.00');

    // Configure IT asset book and verify 50% first year on D.M. 1988 (15.5% -> 7.75% = 1,550 in Y1, 3,100 in Y2)
    $id2 = (string) $a2->json('data.id');
    $this->postJson("/api/v1/eam/assets/{$id2}/capitalize", ['capitalization_date' => date('Y').'-03-01'])->assertOk();
    $parallel2 = $this->getJson("/api/v1/eam/assets/{$id2}/parallel-schedules?period_type=yearly&periods=5")->assertOk();
    expect($parallel2->json('data.rows.0.civil_depreciation'))->toBe('4000.00')
        ->and($parallel2->json('data.rows.0.fiscal_depreciation'))->toBe('1550.00')
        ->and($parallel2->json('data.rows.1.fiscal_depreciation'))->toBe('3100.00')
        ->and((float) $parallel2->json('data.rows.0.incentive_tax_credit'))->toBeGreaterThan(1333.0);

    // Override book explicitly via PUT /books/{ledgerType}
    $this->putJson("/api/v1/eam/assets/{$id1}/books/fiscal", [
        'fiscal_regime' => 'ch_afc_linear',
        'depreciation_method' => 'straight_line',
        'rate_percent' => '15.0000',
        'switch_to_straight_line' => true,
        'immediate_expensing' => false,
    ])->assertOk()->assertJsonPath('data.fiscal_regime', 'ch_afc_linear')->assertJsonPath('data.rate_percent', '15.0000');
});

test('records direct capital improvements without corrupting historical periods and supports multi-book impairment', function (): void {
    $category = mod020Category($this->tenant);
    $year = (int) date('Y');

    $assetId = (string) $this->postJson('/api/v1/eam/assets', [
        'name' => 'Production Press',
        'category_id' => $category->id,
        'acquisition_cost' => '10000.00',
        'useful_life_years' => 5,
        'pro_rata_convention' => 'full',
        'in_service_date' => "{$year}-01-01",
    ])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/eam/assets/{$assetId}/capitalize", ['capitalization_date' => "{$year}-01-01"])->assertOk();

    // Post Year depreciation first: 2,000 civil -> NBV 8,000
    $this->postJson('/api/v1/eam/depreciation/year-end-batch', ['year' => $year, 'auto_post_to_gl' => true])->assertCreated();

    // Record 4,000 capital improvement extending useful life by 12 months
    $this->postJson("/api/v1/eam/assets/{$assetId}/improve", [
        'amount' => '4000.00',
        'description' => 'Automated robotic feeder upgrade',
        'improvement_date' => "{$year}-12-31",
        'extends_useful_life_months' => 12,
        'affects_tax_book' => true,
    ])->assertCreated()->assertJsonPath('data.amount', '4000.00');

    $asset = FixedAsset::findOrFail($assetId);
    expect($asset->capitalized_cost)->toBe('14000.00')
        ->and($asset->carrying_amount)->toBe('12000.00')
        ->and(AssetBook::where('asset_id', $assetId)->where('ledger_type', 'tax')->value('cost'))->toBe('14000.00')
        ->and($asset->depreciationSchedules()->where('ledger_type', 'statutory')->where('period_code', (string) $year)->value('depreciation_amount'))->toBe('2000.00');

    // Permanent impairment applied to both statutory and tax books (CH CO Art. 960a)
    $this->postJson("/api/v1/eam/assets/{$assetId}/valuate", [
        'valuation_type' => 'impairment',
        'fair_value' => '9000.00',
        'valuation_date' => "{$year}-12-31",
        'apply_to_tax_book' => true,
        'reason' => 'Obsolescence write-down',
    ])->assertCreated()->assertJsonPath('data.impairment_loss_amount', '3000.00');

    expect(FixedAsset::findOrFail($assetId)->carrying_amount)->toBe('9000.00')
        ->and(AssetBook::where('asset_id', $assetId)->where('ledger_type', 'tax')->value('net_book_value'))->toBe('9000.00');
});

test('previews and executes sale disposal linked to sales invoice with civil and fiscal gain/loss', function (): void {
    $category = mod020Category($this->tenant);
    $chRate = FiscalDepreciationRate::where('category_code', 'CH-MACH-GEN')->firstOrFail();
    $year = (int) date('Y');

    $buyer = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Buyer SA', 'type' => 'client', 'country' => 'CH']);
    $salesInvoice = Invoice::create([
        'tenant_id' => $this->tenant->id,
        'contact_id' => $buyer->id,
        'invoice_number' => 'INV-SALE-2026-01',
        'date' => "{$year}-12-15",
        'currency' => 'CHF',
        'total_net' => 7500.00,
        'total_tax' => 607.50,
        'total_gross' => 8107.50,
        'payment_status' => 'unpaid',
    ]);

    $assetId = (string) $this->postJson('/api/v1/eam/assets', [
        'name' => 'Milling Machine',
        'category_id' => $category->id,
        'acquisition_cost' => '10000.00',
        'useful_life_years' => 5,
        'pro_rata_convention' => 'full',
        'fiscal_jurisdiction' => 'CH',
        'fiscal_rate_id' => $chRate->id,
        'in_service_date' => "{$year}-01-01",
    ])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/eam/assets/{$assetId}/capitalize", ['capitalization_date' => "{$year}-01-01"])->assertOk();
    $this->postJson('/api/v1/eam/depreciation/year-end-batch', ['year' => $year, 'auto_post_to_gl' => true])->assertCreated();

    // Civil NBV = 8,000; Fiscal NBV = 7,000 (30% CH AFC). Sale proceeds = 7,500 -> Civil Loss -500, Fiscal Gain +500!
    $preview = $this->postJson("/api/v1/eam/assets/{$assetId}/dispose/preview", [
        'disposal_type' => 'sale',
        'sales_invoice_id' => $salesInvoice->id,
        'disposal_date' => "{$year}-12-20",
    ])->assertOk();

    expect($preview->json('data.civil.gain_loss_amount'))->toBe('-500.00')
        ->and($preview->json('data.fiscal.gain_loss_amount'))->toBe('500.00')
        ->and($preview->json('data.disposal_document_number'))->toBe('INV-SALE-2026-01');

    $disposal = $this->postJson("/api/v1/eam/assets/{$assetId}/dispose", [
        'disposal_type' => 'sale',
        'sales_invoice_id' => $salesInvoice->id,
        'disposal_date' => "{$year}-12-20",
    ])->assertCreated();

    expect($disposal->json('data.gain_loss_amount'))->toBe('-500.00')
        ->and($disposal->json('data.fiscal_gain_loss_amount'))->toBe('500.00')
        ->and($disposal->json('data.sales_invoice_id'))->toBe($salesInvoice->id);
});

test('automatically and idempotently creates fixed asset when vendor purchase invoice with capital goods is posted', function (): void {
    $category = mod020Category($this->tenant);
    $supplier = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Macchine Srl', 'type' => 'supplier', 'country' => 'IT']);
    $assetAccount = ChartOfAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'account_code' => '1600',
        'code' => '1600',
        'name' => 'Fixed assets',
        'type' => 'asset',
        'account_class' => 'asset',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'currency' => 'CHF',
        'is_active' => true,
    ]);

    $invoice = PassiveInvoice::create([
        'tenant_id' => $this->tenant->id,
        'supplier_id' => $supplier->id,
        'invoice_number' => 'FV-CAP-2026-99',
        'date' => date('Y').'-02-10',
        'total_amount' => 15000.00,
        'total_net' => 15000.00,
        'total_tax' => 0.00,
        'currency' => 'CHF',
        'document_type' => 'TD01',
    ]);

    $line = PassiveInvoiceLine::create([
        'tenant_id' => $this->tenant->id,
        'passive_invoice_id' => $invoice->id,
        'line_number' => 1,
        'description' => 'Laser Cutting Head',
        'quantity' => 1,
        'unit_price' => 15000.00,
        'line_total' => 15000.00,
        'tax_rate' => 0.00,
        'cost_account_id' => $assetAccount->id,
        'is_capital_asset' => true,
        'asset_category_id' => $category->id,
    ]);

    app(PostPassiveInvoiceAction::class)->execute($invoice);
    // Replay must be idempotent
    app(PostPassiveInvoiceAction::class)->execute($invoice);

    $assets = FixedAsset::where('tenant_id', $this->tenant->id)->where('purchase_invoice_line_id', $line->id)->get();
    expect($assets)->toHaveCount(1)
        ->and($assets->first()->status)->toBe('capitalized')
        ->and($assets->first()->purchase_invoice_number)->toBe('FV-CAP-2026-99')
        ->and($assets->first()->capitalized_cost)->toBe('15000.00');

    // Should not double-post capitalization GL entry when invoice line already posted to 1600
    expect(JournalEntry::where('source_document_type', 'eam_fixed_asset')->where('source_document_id', $assets->first()->id)->count())->toBe(0);
});

test('year-end batch enforces period lock and supports manual post-to-gl for unposted statutory runs', function (): void {
    $category = mod020Category($this->tenant);
    $year = (int) date('Y');

    $assetId = (string) $this->postJson('/api/v1/eam/assets', [
        'name' => 'Server Rack',
        'category_id' => $category->id,
        'acquisition_cost' => '12000.00',
        'useful_life_years' => 4,
        'pro_rata_convention' => 'full',
        'in_service_date' => "{$year}-01-01",
    ])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/eam/assets/{$assetId}/capitalize", ['capitalization_date' => "{$year}-01-01"])->assertOk();

    // Execute without GL posting first
    $batch = $this->postJson('/api/v1/eam/depreciation/year-end-batch', ['year' => $year, 'auto_post_to_gl' => false])->assertCreated();
    $runId = (string) $batch->json('data.statutory_run.id');
    expect($batch->json('data.statutory_run.status'))->toBe('calculated');

    // Lock December period for FA and confirm post-to-gl refuses
    FiscalPeriod::where('tenant_id', $this->tenant->id)->where('period_number', 12)->update(['status' => 'closed']);
    $this->postJson("/api/v1/eam/depreciation/runs/{$runId}/post-to-gl")->assertUnprocessable();

    // Reopen and post cleanly
    FiscalPeriod::where('tenant_id', $this->tenant->id)->where('period_number', 12)->update(['status' => 'open']);
    $this->postJson("/api/v1/eam/depreciation/runs/{$runId}/post-to-gl")->assertOk()->assertJsonPath('data.status', 'posted');
    expect(DepreciationRun::findOrFail($runId)->status)->toBe('posted');

    // Re-posting is refused idempotently
    $this->postJson("/api/v1/eam/depreciation/runs/{$runId}/post-to-gl")->assertUnprocessable();
});

test('exports official Fixed Asset Register in JSON, CSV, and PDF compliant with Art 16 DPR 600/73 and Art 958c CO', function (): void {
    $category = mod020Category($this->tenant);
    $year = (int) date('Y');

    $assetId = (string) $this->postJson('/api/v1/eam/assets', [
        'name' => 'Industrial Compressor',
        'category_id' => $category->id,
        'acquisition_cost' => '10000.00',
        'useful_life_years' => 5,
        'pro_rata_convention' => 'full',
        'in_service_date' => "{$year}-01-01",
    ])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/eam/assets/{$assetId}/capitalize", ['capitalization_date' => "{$year}-01-01"])->assertOk();
    $this->postJson('/api/v1/eam/depreciation/year-end-batch', ['year' => $year, 'auto_post_to_gl' => true])->assertCreated();

    $jsonIt = $this->getJson("/api/v1/eam/reports/asset-register?year={$year}&jurisdiction=IT")->assertOk();
    expect($jsonIt->json('meta.compliance_header.legal_reference'))->toContain('Art. 16 D.P.R. 600/1973')
        ->and($jsonIt->json('meta.totals.depreciation_year'))->toBe('2000.00')
        ->and($jsonIt->json('meta.category_subtotals'))->toHaveCount(1);

    $jsonCh = $this->getJson("/api/v1/eam/reports/asset-register?year={$year}&jurisdiction=CH")->assertOk();
    expect($jsonCh->json('meta.compliance_header.legal_reference'))->toContain('Art. 958c');

    $pdf = $this->get("/api/v1/eam/reports/asset-register?year={$year}&format=pdf&jurisdiction=CH&locale=de-CH")->assertOk();
    expect($pdf->headers->get('Content-Type'))->toContain('application/pdf')
        ->and($pdf->getContent())->toStartWith('%PDF-');
});

test('enforces tenant isolation, read-only member 403, and 4-locale backend/frontend translation parity', function (): void {
    $category = mod020Category($this->tenant);
    $assetId = (string) $this->postJson('/api/v1/eam/assets', [
        'name' => 'Tenant A Asset',
        'category_id' => $category->id,
        'acquisition_cost' => '5000.00',
    ])->assertCreated()->json('data.id');

    // Read-only member cannot execute writes
    Sanctum::actingAs($this->member);
    $this->postJson("/api/v1/eam/assets/{$assetId}/improve", ['amount' => '100.00', 'description' => 'Test'])->assertForbidden();
    $this->postJson('/api/v1/eam/depreciation/year-end-batch', ['year' => (int) date('Y')])->assertForbidden();

    // Cross-tenant access refused
    Sanctum::actingAs($this->admin);
    $otherUser = User::create(['name' => 'Other Admin', 'email' => Str::uuid().'@example.test', 'password' => bcrypt(Str::password(16))]);
    $otherUser->tenants()->attach($this->otherTenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin', 'permissions' => json_encode([])]);
    Sanctum::actingAs($otherUser);
    $this->withHeader('X-Tenant-Id', (string) $this->otherTenant->id);
    $this->getJson("/api/v1/eam/assets/{$assetId}/parallel-schedules")->assertNotFound();
    $this->postJson("/api/v1/eam/assets/{$assetId}/improve", ['amount' => '100.00', 'description' => 'Leak'])->assertNotFound();

    // Locale parity across all 4 base dictionaries
    $enKeys = array_keys(FixedAssetsFrontendTranslations::all('en'));
    foreach (['it', 'de', 'fr'] as $locale) {
        expect(array_keys(FixedAssetsFrontendTranslations::all($locale)))->toBe($enKeys);
        expect(array_keys(__('eam', [], $locale)))->toBe(array_keys(__('eam', [], 'en')));
    }
});
