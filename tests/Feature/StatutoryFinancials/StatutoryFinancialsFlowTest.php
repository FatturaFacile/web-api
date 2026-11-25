<?php

declare(strict_types=1);

use App\Models\Tenant;
use Database\Seeders\StatutoryFinancialsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\StatutoryFinancials\Models\LineDefinitionRecord;
use Modules\StatutoryFinancials\Models\TextTemplate;

require_once __DIR__.'/../Treasury/TreasurySupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(StatutoryFinancialsReferenceSeeder::class);
    $this->tenant = Tenant::create(['name' => 'Bilancio Srl', 'legal_name' => 'Bilancio Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->otherTenant = Tenant::create(['name' => 'Altra Srl', 'legal_name' => 'Altra Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    fiscalCalendar($this->tenant, 2028);
    $this->year = FiscalYear::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->actor = treasuryActor($this, 'accountant');
    sfsLedger($this);
});

/**
 * A small ledger: share capital paid in, a sale and a cost, all posted in the fiscal year.
 */
function sfsLedger(object $test): void
{
    $accounts = [];

    foreach ([['1010', 'Banca', 'asset', 'debit'], ['2010', 'Capitale sociale', 'equity', 'credit'], ['4010', 'Ricavi vendite', 'revenue', 'credit'], ['6010', 'Costi servizi', 'expense', 'debit']] as [$code, $name, $type, $normal]) {
        $accounts[$code] = treasuryInTenant($test->tenant, fn (): ChartOfAccount => ChartOfAccount::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid7(), 'tenant_id' => $test->tenant->id, 'account_code' => $code, 'name' => $name,
            'account_class' => $type, 'account_type' => $type, 'normal_balance' => $normal, 'currency' => 'EUR', 'is_active' => true,
        ]));
    }

    $period = FiscalPeriod::withoutGlobalScopes()->where('fiscal_year_id', $test->year->id)->orderBy('period_number')->firstOrFail();
    $entries = [
        ['Capital', [['1010', '100000', '0'], ['2010', '0', '100000']]],
        ['Sale', [['1010', '50000.40', '0'], ['4010', '0', '50000.40']]],
        ['Cost', [['6010', '20000.20', '0'], ['1010', '0', '20000.20']]],
    ];

    foreach ($entries as $index => [$description, $lines]) {
        $id = (string) Str::uuid7();
        DB::table('journal_entries')->insert([
            'id' => $id, 'tenant_id' => $test->tenant->id, 'fiscal_period_id' => $period->id, 'fiscal_year_id' => $test->year->id, 'entry_number' => 'SFS-'.($index + 1),
            'posting_date' => '2028-01-15', 'document_date' => '2028-01-15', 'ledger_type' => 'statutory', 'journal_type' => 'standard', 'description' => $description,
            'status' => 'posted', 'total_amount' => '100000', 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($lines as $number => [$code, $debit, $credit]) {
            DB::table('journal_lines')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $test->tenant->id, 'journal_entry_id' => $id, 'posting_date' => '2028-01-15', 'line_number' => $number + 1,
                'account_id' => $accounts[$code]->id, 'description' => $description, 'debit_amount' => $debit, 'credit_amount' => $credit, 'currency' => 'EUR',
                'exchange_rate' => '1', 'amount_in_functional_currency' => max($debit, $credit), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}

/**
 * @param  array<string, mixed>  $overrides
 */
function sfsCreate(object $test, array $overrides = []): string
{
    return $test->postJson('/api/v1/statutory-financials/dossiers', array_merge([
        'fiscal_year_id' => $test->year->id, 'accounting_standard' => 'IT_OIC_Ordinario', 'rounding_to_unit_mode' => 'IntegerUnits_Euro_Chf', 'share_capital' => '100000',
    ], $overrides))->assertCreated()->json('data.id');
}

it('seeds the line catalogue and the wording as global records, idempotently', function (): void {
    $lines = LineDefinitionRecord::withoutGlobalScopes()->count();
    $texts = TextTemplate::withoutGlobalScopes()->count();

    expect($lines)->toBeGreaterThan(100)->and($texts)->toBeGreaterThan(30);

    $this->seed(StatutoryFinancialsReferenceSeeder::class);

    expect(LineDefinitionRecord::withoutGlobalScopes()->count())->toBe($lines)->and(TextTemplate::withoutGlobalScopes()->count())->toBe($texts)
        ->and(LineDefinitionRecord::withoutGlobalScopes()->whereNotNull('tenant_id')->count())->toBe(0);
});

it('serves the lookups from the API', function (): void {
    $this->getJson('/api/v1/statutory-financials/lookups')->assertOk()->assertJsonPath('success', true);
});

it('creates a dossier and generates the reclassified statements', function (): void {
    $id = sfsCreate($this);

    $this->postJson("/api/v1/statutory-financials/dossiers/{$id}/generate")->assertOk();
    $this->getJson("/api/v1/statutory-financials/dossiers/{$id}/preview")->assertOk();
    $this->getJson("/api/v1/statutory-financials/dossiers/{$id}")->assertOk()->assertJsonPath('data.status', 'reclassified');
});

it('populates the notes from the subledgers and computes the crisis indicators', function (): void {
    $id = sfsCreate($this);
    $this->postJson("/api/v1/statutory-financials/dossiers/{$id}/generate")->assertOk();

    $this->postJson("/api/v1/statutory-financials/dossiers/{$id}/notes/populate", [])->assertOk();
    $this->getJson("/api/v1/statutory-financials/dossiers/{$id}/notes")->assertOk();
    $this->getJson("/api/v1/statutory-financials/dossiers/{$id}/indicators")->assertOk();
});

it('exports the XBRL instance and exposes the deposit package', function (): void {
    $id = sfsCreate($this);
    $this->postJson("/api/v1/statutory-financials/dossiers/{$id}/generate")->assertOk();
    $this->postJson("/api/v1/statutory-financials/dossiers/{$id}/xbrl/export")->assertOk();
    $this->getJson("/api/v1/statutory-financials/dossiers/{$id}/package")->assertOk();
});

it('refuses cross-tenant access to a dossier', function (): void {
    $id = sfsCreate($this);
    treasuryActor($this, 'accountant', $this->otherTenant);

    $this->getJson("/api/v1/statutory-financials/dossiers/{$id}")->assertNotFound();
    $this->postJson("/api/v1/statutory-financials/dossiers/{$id}/generate")->assertNotFound();
});

it('forbids a read-only member from writing', function (): void {
    treasuryActor($this, 'viewer');

    $this->postJson('/api/v1/statutory-financials/dossiers', ['fiscal_year_id' => $this->year->id, 'accounting_standard' => 'IT_OIC_Ordinario'])->assertForbidden();
});

it('validates the payload of a dossier', function (): void {
    $this->postJson('/api/v1/statutory-financials/dossiers', ['fiscal_year_id' => $this->year->id, 'accounting_standard' => 'XX'])->assertUnprocessable();
    $this->postJson('/api/v1/statutory-financials/dossiers', ['accounting_standard' => 'IT_OIC_Ordinario'])->assertUnprocessable();
});

it('cannot file a dossier before a valid XBRL instance exists and keeps drafts deletable', function (): void {
    $id = sfsCreate($this);

    $this->postJson("/api/v1/statutory-financials/dossiers/{$id}/transition", ['target' => 'filed'])->assertUnprocessable();
    $this->deleteJson("/api/v1/statutory-financials/dossiers/{$id}")->assertSuccessful();
    $this->getJson("/api/v1/statutory-financials/dossiers/{$id}")->assertNotFound();
});
