<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Models\AccountMapping;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\CostCenter;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\GeneralLedger\Models\VatCode;
use Modules\GeneralLedger\Services\ManualJournalService;

function actingAsCoaAdmin(): array
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);
    Sanctum::actingAs($user);

    return [$tenant, $user];
}

it('provisions Swiss KMU/Käfer and Italian OIC templates idempotently in 1 click with default mappings', function (): void {
    [$tenant] = actingAsCoaAdmin();

    $templatesRes = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
        ->getJson('/api/v1/general-ledger/account-templates');

    $templatesRes->assertOk()
        ->assertJsonStructure(['data', 'ui_copy' => ['page_title', 'provision_button', 'export_xlsx']]);

    $provisionSwiss = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
        ->postJson('/api/v1/general-ledger/account-templates/ch_kmu_pme/import', [
            'include_default_mappings' => true,
            'locale' => 'it',
        ]);

    $provisionSwiss->assertOk();
    expect($provisionSwiss->json('data.created'))->toBeGreaterThan(10)
        ->and($provisionSwiss->json('data.mappings_created'))->toBeGreaterThan(0);

    // Re-running 1-click provisioning is idempotent (0 created, all skipped)
    $repeatSwiss = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
        ->postJson('/api/v1/general-ledger/account-templates/ch_kmu_pme/import', [
            'include_default_mappings' => true,
        ]);

    $repeatSwiss->assertOk();
    expect($repeatSwiss->json('data.created'))->toBe(0)
        ->and($repeatSwiss->json('data.skipped'))->toBeGreaterThan(10);

    // Provision Italian OIC alongside Swiss KMU (different codes)
    $provisionOic = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
        ->postJson('/api/v1/general-ledger/account-templates/it_general_ledger/import', [
            'include_default_mappings' => true,
        ]);

    $provisionOic->assertOk();
    expect($provisionOic->json('data.created'))->toBeGreaterThan(10);
});

it('supports unlimited hierarchy depth, cycle-safe move, and breadcrumbs', function (): void {
    [$tenant] = actingAsCoaAdmin();

    $root = ChartOfAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'account_code' => '1',
        'name' => 'Attivo',
        'account_name' => 'Attivo',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'is_postable' => false,
        'allows_manual_posting' => false,
        'is_active' => true,
    ]);

    $level2 = ChartOfAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'account_code' => '10',
        'name' => 'Attivo circolante',
        'account_name' => 'Attivo circolante',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'parent_account_id' => $root->id,
        'is_postable' => false,
        'allows_manual_posting' => false,
        'is_active' => true,
    ]);

    $level3 = ChartOfAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'account_code' => '102',
        'name' => 'Banche',
        'account_name' => 'Banche',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'parent_account_id' => $level2->id,
        'is_postable' => false,
        'allows_manual_posting' => false,
        'is_active' => true,
    ]);

    $level4 = ChartOfAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'account_code' => '1020.01',
        'name' => 'UBS CHF',
        'account_name' => 'UBS CHF',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'parent_account_id' => $level3->id,
        'is_postable' => true,
        'allows_manual_posting' => true,
        'is_active' => true,
    ]);

    // Cycle prevention: moving root under its own descendant level4 must fail with 422
    $this->withHeader('X-Tenant-ID', (string) $tenant->id)
        ->postJson("/api/v1/general-ledger/accounts/{$root->id}/move", [
            'parent_account_id' => $level4->id,
        ])
        ->assertUnprocessable();

    // Valid move: promote level3 directly under root
    $this->withHeader('X-Tenant-ID', (string) $tenant->id)
        ->postJson("/api/v1/general-ledger/accounts/{$level3->id}/move", [
            'parent_account_id' => $root->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.level', 2)
        ->assertJsonPath('data.hierarchy_path', '1/102');

    $level4->refresh();
    expect($level4->level)->toBe(3)
        ->and($level4->hierarchy_path)->toBe('1/102/1020.01');

    // Tree and breadcrumbs check
    $treeRes = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
        ->getJson('/api/v1/general-ledger/accounts-tree');
    $treeRes->assertOk()
        ->assertJsonPath('meta.total_accounts', 4);

    $detailRes = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
        ->getJson("/api/v1/general-ledger/accounts/{$level4->id}");
    $detailRes->assertOk()
        ->assertJsonCount(3, 'meta.breadcrumbs');
});

it('validates and stores accounting properties and enforces journal entry rules', function (): void {
    [$tenant] = actingAsCoaAdmin();

    $vatCode = VatCode::withoutGlobalScopes()->create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'code' => 'IVA22',
        'country_iso' => 'IT',
        'name' => 'IVA 22%',
        'rate' => 22.0,
        'is_active' => true,
    ]);

    $costCenter = CostCenter::withoutGlobalScopes()->create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'code' => 'CC-OPS',
        'name' => 'Operations',
        'is_active' => true,
    ]);

    $groupAccount = ChartOfAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'account_code' => '6',
        'name' => 'Altri costi',
        'account_name' => 'Altri costi',
        'account_type' => 'expense',
        'normal_balance' => 'debit',
        'is_postable' => false,
        'allows_manual_posting' => false,
        'is_active' => true,
    ]);

    $postRes = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
        ->postJson('/api/v1/general-ledger/accounts', [
            'account_code' => '6500',
            'name' => 'Consulenze',
            'account_type' => 'expense',
            'normal_balance' => 'debit',
            'parent_account_id' => $groupAccount->id,
            'vat_code_id' => $vatCode->id,
            'default_cost_center_id' => $costCenter->id,
            'open_items_enabled' => true,
            'allowed_currencies' => ['CHF'],
            'is_postable' => true,
            'statutory_code' => 'OIC-B7',
        ]);

    $postRes->assertCreated()
        ->assertJsonPath('data.vat_code_id', $vatCode->id)
        ->assertJsonPath('data.default_cost_center_id', $costCenter->id)
        ->assertJsonPath('data.open_items_enabled', true)
        ->assertJsonPath('data.allowed_currencies.0', 'CHF');

    $opId = (string) $postRes->json('data.id');

    /** @var ManualJournalService $journalService */
    $journalService = app(ManualJournalService::class);

    // Non-postable group account must be rejected
    expect(fn () => $journalService->assertManualLines((string) $tenant->id, [
        ['account_id' => (string) $groupAccount->id, 'debit' => 100, 'credit' => 0],
    ]))->toThrow(ValidationException::class);

    // Disallowed currency must be rejected
    expect(fn () => $journalService->assertManualLines((string) $tenant->id, [
        ['account_id' => $opId, 'debit' => 100, 'credit' => 0],
    ], 'USD'))->toThrow(ValidationException::class);

    // Allowed currency passes
    $journalService->assertManualLines((string) $tenant->id, [
        ['account_id' => $opId, 'debit' => 100, 'credit' => 0],
    ], 'CHF');
});

it('protects accounts with journal postings from deletion and structural mutations while allowing rename and deactivation', function (): void {
    [$tenant] = actingAsCoaAdmin();

    $account = ChartOfAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'account_code' => '1000',
        'name' => 'Cassa',
        'account_name' => 'Cassa',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'is_postable' => true,
        'allows_manual_posting' => true,
        'is_active' => true,
    ]);

    $year = FiscalYear::create(['id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'year_code' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
    $period = FiscalPeriod::create(['id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'fiscal_year_id' => $year->id, 'period_number' => 1, 'period_name' => 'January 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'open']);

    $entry = JournalEntry::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'fiscal_period_id' => $period->id,
        'entry_number' => 'JE-TEST-001',
        'posting_date' => '2026-01-15',
        'document_date' => '2026-01-15',
        'description' => 'Opening balance',
        'journal_type' => 'manual',
        'status' => 'posted',
        'currency' => 'CHF',
    ]);

    JournalLine::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'journal_entry_id' => $entry->id,
        'account_id' => $account->id,
        'line_number' => 1,
        'description' => 'Opening balance',
        'debit_amount' => 100.00,
        'credit_amount' => 0.00,
    ]);

    // Deletion blocked with 409
    $this->withHeader('X-Tenant-ID', (string) $tenant->id)
        ->deleteJson("/api/v1/general-ledger/accounts/{$account->id}")
        ->assertStatus(409);

    // Changing account_type or account_code blocked with 409
    $this->withHeader('X-Tenant-ID', (string) $tenant->id)
        ->putJson("/api/v1/general-ledger/accounts/{$account->id}", [
            'account_code' => '1001',
            'name' => 'Cassa Principale',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
        ])
        ->assertStatus(409);

    // Rename + deactivation allowed
    $this->withHeader('X-Tenant-ID', (string) $tenant->id)
        ->putJson("/api/v1/general-ledger/accounts/{$account->id}", [
            'account_code' => '1000',
            'name' => 'Cassa Storica',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);
});

it('manages bidirectional cross-standard mappings and validates self-mapping and split allocations', function (): void {
    [$tenant] = actingAsCoaAdmin();

    $src = ChartOfAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'account_code' => '1020',
        'name' => 'Banca CHF',
        'account_name' => 'Banca CHF',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'template_code' => 'ch_kmu_pme',
        'is_active' => true,
    ]);

    $dst = ChartOfAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'account_code' => '22.01',
        'name' => 'Depositi bancari e postali',
        'account_name' => 'Depositi bancari e postali',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'template_code' => 'it_general_ledger',
        'is_active' => true,
    ]);

    // Self-mapping rejected
    $this->withHeader('X-Tenant-ID', (string) $tenant->id)
        ->postJson('/api/v1/general-ledger/account-mappings', [
            'source_account_id' => $src->id,
            'target_account_id' => $src->id,
            'target_standard' => 'it_general_ledger',
            'target_account_code' => '1020',
            'target_account_name' => 'Self',
        ])
        ->assertUnprocessable();

    // Split mapping 60% succeeds
    $map1 = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
        ->postJson('/api/v1/general-ledger/account-mappings', [
            'source_account_id' => $src->id,
            'target_account_id' => $dst->id,
            'source_standard' => 'ch_kmu_pme',
            'target_standard' => 'it_general_ledger',
            'target_account_code' => '22.01',
            'target_account_name' => 'Depositi bancari',
            'mapping_type' => 'split',
            'allocation_percentage' => 60,
            'is_bidirectional' => true,
        ]);
    $map1->assertCreated();

    // Split mapping +50% (>100% total) fails validation
    $this->withHeader('X-Tenant-ID', (string) $tenant->id)
        ->postJson('/api/v1/general-ledger/account-mappings', [
            'source_account_id' => $src->id,
            'source_standard' => 'ch_kmu_pme',
            'target_standard' => 'it_general_ledger',
            'target_account_code' => '22.02',
            'target_account_name' => 'Assegni',
            'mapping_type' => 'split',
            'allocation_percentage' => 50,
        ])
        ->assertUnprocessable();

    // Bidirectional incoming lookup from dst perspective returns the mapping
    $incomingRes = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
        ->getJson("/api/v1/general-ledger/account-mappings?source_account_id={$dst->id}");
    $incomingRes->assertOk()
        ->assertJsonCount(1, 'incoming');

    expect(AccountMapping::where('tenant_id', $tenant->id)->count())->toBe(1);
});

it('exports audit-ready Chart of Accounts in XLSX, CSV, and XML formats', function (): void {
    [$tenant] = actingAsCoaAdmin();

    $this->withHeader('X-Tenant-ID', (string) $tenant->id)
        ->postJson('/api/v1/general-ledger/account-templates/ch_kmu_pme/import', [
            'include_default_mappings' => true,
        ])
        ->assertOk();

    $csvRes = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
        ->get('/api/v1/general-ledger/accounts/export?format=csv');
    $csvRes->assertOk();
    expect($csvRes->headers->get('content-type'))->toContain('text/csv')
        ->and($csvRes->getContent())->toContain('"account_code";"account_name"');

    $xmlRes = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
        ->get('/api/v1/general-ledger/accounts/export?format=xml');
    $xmlRes->assertOk();
    expect($xmlRes->headers->get('content-type'))->toContain('application/xml')
        ->and($xmlRes->getContent())->toContain('<ChartOfAccountsAuditExport');

    $xlsxRes = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
        ->get('/api/v1/general-ledger/accounts/export?format=xlsx');
    $xlsxRes->assertOk();
    expect($xlsxRes->headers->get('content-type'))->toContain('spreadsheetml.sheet')
        ->and(substr((string) $xlsxRes->getContent(), 0, 2))->toBe('PK');
});
