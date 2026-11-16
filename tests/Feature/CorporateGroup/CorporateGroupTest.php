<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Enterprise\MultiTenant\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\CorporateGroup\Models\Company;
use Modules\CorporateGroup\Models\CompanyAccountingSetting;
use Modules\CorporateGroup\Models\CompanyMember;
use Modules\CorporateGroup\Services\ConsolidationService;
use Modules\CorporateGroup\Services\IntercompanyService;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalEntry;

uses(RefreshDatabase::class);

/**
 * A company with its own chart of accounts, fiscal calendar and intercompany account mapping.
 *
 * @return array{company: Company, accounts: array<string, ChartOfAccount>, period: FiscalPeriod}
 */
function corpCompany(string $tenantId, string $code, string $currency = 'EUR'): array
{
    $company = Company::create(['tenant_id' => $tenantId, 'code' => $code, 'legal_name' => $code.' Srl', 'currency' => $currency]);

    $year = FiscalYear::create(['tenant_id' => $tenantId, 'company_id' => $company->id, 'year_code' => 'FY2026-'.$code, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
    $period = FiscalPeriod::create(['tenant_id' => $tenantId, 'company_id' => $company->id, 'fiscal_year_id' => $year->id, 'period_number' => 1, 'period_name' => 'FY2026 '.$code, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);

    $accounts = [];
    foreach ([
        '1000' => ['Bank', 'asset', 'debit'], '1300' => ['IC receivable', 'asset', 'debit'], '1400' => ['Input VAT', 'asset', 'debit'],
        '2200' => ['Output VAT', 'liability', 'credit'], '2300' => ['IC payable', 'liability', 'credit'],
        '4100' => ['Revenue', 'revenue', 'credit'], '5100' => ['Cost', 'expense', 'debit'],
    ] as $accountCode => [$name, $type, $normal]) {
        $accounts[$accountCode] = ChartOfAccount::create([
            'tenant_id' => $tenantId, 'company_id' => $company->id, 'account_code' => $accountCode, 'name' => $name,
            'account_class' => $type, 'account_type' => $type, 'normal_balance' => $normal, 'is_active' => true,
        ]);
    }

    CompanyAccountingSetting::create([
        'tenant_id' => $tenantId, 'company_id' => $company->id,
        'ic_receivable_account_id' => $accounts['1300']->id, 'ic_payable_account_id' => $accounts['2300']->id,
        'ic_revenue_account_id' => $accounts['4100']->id, 'ic_expense_account_id' => $accounts['5100']->id,
        'vat_payable_account_id' => $accounts['2200']->id, 'vat_receivable_account_id' => $accounts['1400']->id,
    ]);

    return ['company' => $company, 'accounts' => $accounts, 'period' => $period];
}

beforeEach(function (): void {
    ['tenant' => $this->tenant, 'user' => $this->owner] = logisticsTenant('Group');
    app(TenantContext::class)->setTenant($this->tenant);
    $this->tenantId = (string) $this->tenant->id;
    $this->a = corpCompany($this->tenantId, 'ITA');
    $this->b = corpCompany($this->tenantId, 'ITB');
    $this->ic = app(IntercompanyService::class);
});

function icTransaction(object $t, array $override = [])
{
    return $t->ic->record($t->tenantId, array_merge([
        'seller_company_id' => $t->a['company']->id,
        'buyer_company_id' => $t->b['company']->id,
        'transaction_date' => '2026-03-10',
        'net_amount' => 1000,
        'tax_amount' => 220,
        'description' => 'Goods transfer',
    ], $override));
}

test('an intercompany operation books documents and mirrored journal entries on both companies', function (): void {
    $tx = icTransaction($this);

    expect($tx->status)->toBe('posted')->and((float) $tx->gross_amount)->toBe(1220.0);

    // Documents on both sides: sales order + invoice for the seller, purchase order + invoice for the buyer.
    $docs = $tx->documents->groupBy('company_id');
    expect($docs[$this->a['company']->id]->pluck('document_type')->sort()->values()->all())->toBe(['sales_invoice', 'sales_order'])
        ->and($docs[$this->b['company']->id]->pluck('document_type')->sort()->values()->all())->toBe(['purchase_invoice', 'purchase_order']);

    $sellerEntry = JournalEntry::withoutGlobalScopes()->with('lines')->findOrFail($tx->seller_journal_entry_id);
    $buyerEntry = JournalEntry::withoutGlobalScopes()->with('lines')->findOrFail($tx->buyer_journal_entry_id);

    expect($sellerEntry->company_id)->toBe($this->a['company']->id)
        ->and($buyerEntry->company_id)->toBe($this->b['company']->id)
        ->and($sellerEntry->status)->toBe('posted')->and($buyerEntry->status)->toBe('posted');

    // Seller: Dr receivable 1220 / Cr revenue 1000 / Cr VAT 220. Buyer: Dr cost 1000 / Dr VAT 220 / Cr payable 1220.
    $byAccount = fn ($entry) => $entry->lines->mapWithKeys(fn ($l) => [ChartOfAccount::withoutGlobalScopes()->find($l->account_id)->account_code => [(float) $l->debit_amount, (float) $l->credit_amount]]);
    expect($byAccount($sellerEntry)->all())->toBe(['1300' => [1220.0, 0.0], '4100' => [0.0, 1000.0], '2200' => [0.0, 220.0]])
        ->and($byAccount($buyerEntry)->all())->toBe(['5100' => [1000.0, 0.0], '1400' => [220.0, 0.0], '2300' => [0.0, 1220.0]]);

    // The lines of each entry use the accounts of their own company.
    foreach ($sellerEntry->lines as $line) {
        expect(ChartOfAccount::withoutGlobalScopes()->find($line->account_id)->company_id)->toBe($this->a['company']->id);
    }
    foreach ($buyerEntry->lines as $line) {
        expect(ChartOfAccount::withoutGlobalScopes()->find($line->account_id)->company_id)->toBe($this->b['company']->id);
    }
});

test('each company numbers its own documents with its own sezionale', function (): void {
    $first = icTransaction($this);
    $second = icTransaction($this);

    $numbers = fn ($tx, $companyId, $type) => $tx->documents->where('company_id', $companyId)->firstWhere('document_type', $type)->document_number;

    expect($numbers($first, $this->a['company']->id, 'sales_invoice'))->toBe('ICSI-2026-00001')
        ->and($numbers($second, $this->a['company']->id, 'sales_invoice'))->toBe('ICSI-2026-00002')
        ->and($numbers($first, $this->b['company']->id, 'purchase_invoice'))->toBe('ICPI-2026-00001')
        ->and($numbers($second, $this->b['company']->id, 'purchase_invoice'))->toBe('ICPI-2026-00002');
});

test('intercompany needs configured accounts, two companies and one shared currency', function (): void {
    CompanyAccountingSetting::query()->where('company_id', $this->b['company']->id)->update(['ic_payable_account_id' => null]);
    expect(fn () => icTransaction($this))->toThrow(InvalidArgumentException::class, 'ic_payable_account_id');

    expect(fn () => icTransaction($this, ['buyer_company_id' => $this->a['company']->id]))->toThrow(InvalidArgumentException::class);

    $usd = corpCompany($this->tenantId, 'USA', 'USD');
    expect(fn () => icTransaction($this, ['buyer_company_id' => $usd['company']->id]))->toThrow(InvalidArgumentException::class, 'Cross-currency');

    // Nothing was booked by the failed attempts.
    expect(JournalEntry::withoutGlobalScopes()->count())->toBe(0);
});

test('cancelling an intercompany transaction reverses both sides', function (): void {
    $tx = icTransaction($this);

    $cancelled = $this->ic->cancel($tx, '2026-03-20');

    expect($cancelled->status)->toBe('cancelled')
        ->and($cancelled->documents->pluck('status')->unique()->all())->toBe(['cancelled'])
        ->and(JournalEntry::withoutGlobalScopes()->find($tx->seller_journal_entry_id)->status)->toBe('reversed')
        ->and(JournalEntry::withoutGlobalScopes()->find($tx->buyer_journal_entry_id)->status)->toBe('reversed');

    expect(fn () => $this->ic->cancel($tx))->toThrow(InvalidArgumentException::class);
});

test('consolidation eliminates intercompany balances and reports the group result', function (): void {
    icTransaction($this);

    // An external sale of company A, which must stay in the group result.
    $posting = app(DoubleEntryPostingServiceInterface::class);
    app(CompanyContext::class)->runWithCompany($this->a['company']->id, fn () => $posting->createAndPost([
        'tenant_id' => $this->tenantId, 'company_id' => $this->a['company']->id, 'fiscal_period_id' => $this->a['period']->id,
        'posting_date' => '2026-04-01', 'document_date' => '2026-04-01', 'description' => 'External sale',
        'lines' => [
            ['account_id' => $this->a['accounts']['1000']->id, 'debit_amount' => 500, 'credit_amount' => 0],
            ['account_id' => $this->a['accounts']['4100']->id, 'debit_amount' => 0, 'credit_amount' => 500],
        ],
    ]));

    $run = app(ConsolidationService::class)->consolidate($this->tenantId, ['period_start' => '2026-01-01', 'period_end' => '2026-12-31']);
    $report = app(ConsolidationService::class)->report($run);

    $line = fn (string $code) => collect($report['trial_balance']['accounts'])->firstWhere('account_code', $code);

    // Receivable/payable and revenue/expense of the intragroup operation disappear from the consolidated books.
    expect($line('1300')['consolidated_debit'] - $line('1300')['consolidated_credit'])->toBe(0.0)
        ->and($line('2300')['consolidated_debit'] - $line('2300')['consolidated_credit'])->toBe(0.0)
        ->and($line('5100')['consolidated_debit'] - $line('5100')['consolidated_credit'])->toBe(0.0)
        ->and($line('4100')['consolidated_credit'] - $line('4100')['consolidated_debit'])->toBe(500.0);

    expect($line('1300')['aggregated_debit'])->toBe(1220.0)
        ->and($line('4100')['companies'])->toHaveCount(1)
        ->and(collect($report['eliminations'])->pluck('type')->sort()->values()->all())->toBe(['receivable_payable', 'revenue_expense'])
        ->and($report['trial_balance']['total_debit'])->toBe($report['trial_balance']['total_credit'])
        ->and($report['income_statement'])->toEqual(['total_revenue' => 500.0, 'total_expenses' => 0.0, 'net_income' => 500.0])
        ->and($report['balance_sheet']['is_balanced'])->toBeTrue()
        ->and($report['balance_sheet']['total_assets'])->toEqual(720.0);
});

test('consolidation excludes equity-method companies and scales proportional ones', function (): void {
    icTransaction($this);
    $this->b['company']->update(['consolidation_method' => 'proportional', 'ownership_percentage' => 50]);

    $run = app(ConsolidationService::class)->consolidate($this->tenantId, ['period_start' => '2026-01-01', 'period_end' => '2026-12-31']);
    $report = app(ConsolidationService::class)->report($run);
    $cost = collect($report['trial_balance']['accounts'])->firstWhere('account_code', '5100');

    expect($cost['aggregated_debit'])->toBe(500.0);

    $this->b['company']->update(['consolidation_method' => 'equity']);
    $run = app(ConsolidationService::class)->consolidate($this->tenantId, ['period_start' => '2026-01-01', 'period_end' => '2026-12-31']);

    expect($run->company_ids)->toBe([$this->a['company']->id])->and($run->eliminations)->toHaveCount(0);
});

test('company context isolates accounts and journals per company', function (): void {
    icTransaction($this);
    $context = app(CompanyContext::class);

    // Without a company the tenant-wide view is unchanged.
    expect(ChartOfAccount::count())->toBe(14)->and(JournalEntry::count())->toBe(2);

    $context->setActive($this->a['company']->id);
    expect(ChartOfAccount::count())->toBe(7)
        ->and(ChartOfAccount::pluck('company_id')->unique()->all())->toBe([$this->a['company']->id])
        ->and(JournalEntry::pluck('company_id')->unique()->all())->toBe([$this->a['company']->id])
        ->and(JournalEntry::find(JournalEntry::withoutGlobalScopes()->where('company_id', $this->b['company']->id)->value('id')))->toBeNull();

    $context->setActive($this->b['company']->id);
    expect(ChartOfAccount::pluck('company_id')->unique()->all())->toBe([$this->b['company']->id])
        ->and(FiscalPeriod::pluck('company_id')->all())->toBe([$this->b['company']->id]);

    // A restricted user without an active company only sees the companies they were granted.
    $context->clear();
    $context->setAllowed([$this->a['company']->id]);
    expect(ChartOfAccount::pluck('company_id')->unique()->all())->toBe([$this->a['company']->id]);
    $context->clear();

    // New rows pick up the active company.
    $context->setActive($this->a['company']->id);
    $account = ChartOfAccount::create(['tenant_id' => $this->tenantId, 'account_code' => '9999', 'name' => 'Scoped', 'account_class' => 'asset', 'account_type' => 'asset', 'normal_balance' => 'debit']);
    expect($account->company_id)->toBe($this->a['company']->id);
    $context->clear();
});

test('rows without company stay visible as generic data in every company', function (): void {
    ChartOfAccount::create(['tenant_id' => $this->tenantId, 'company_id' => null, 'account_code' => '0001', 'name' => 'Shared', 'account_class' => 'asset', 'account_type' => 'asset', 'normal_balance' => 'debit']);

    app(CompanyContext::class)->setActive($this->b['company']->id);

    expect(ChartOfAccount::where('account_code', '0001')->exists())->toBeTrue();
    app(CompanyContext::class)->clear();
});

test('the API limits companies and intercompany to what the user is granted', function (): void {
    $accountant = logisticsUserWithRole($this->tenantId, 'viewer');
    $headers = ['X-Tenant-Id' => $this->tenantId];

    // Owner grants the user access to company A only, as accountant.
    Sanctum::actingAs($this->owner);
    $this->postJson("/api/v1/corporate-group/companies/{$this->a['company']->id}/members", ['user_id' => $accountant->id, 'role' => 'accountant'], $headers)->assertCreated();

    Sanctum::actingAs($accountant);
    $list = $this->getJson('/api/v1/corporate-group/companies', $headers)->assertOk()->assertJsonCount(1, 'data');
    expect($list->json('data.0.id'))->toBe($this->a['company']->id);

    $this->getJson("/api/v1/corporate-group/companies/{$this->b['company']->id}", $headers)->assertForbidden();
    $this->getJson('/api/v1/corporate-group/companies', $headers + ['X-Company-Id' => $this->b['company']->id])->assertForbidden();

    // Booking against company B needs the permission on B as well.
    $this->postJson('/api/v1/corporate-group/intercompany/transactions', [
        'seller_company_id' => $this->a['company']->id, 'buyer_company_id' => $this->b['company']->id,
        'transaction_date' => '2026-03-10', 'net_amount' => 100,
    ], $headers)->assertForbidden();

    // Only owners/admins create companies.
    $this->postJson('/api/v1/corporate-group/companies', ['code' => 'NEW', 'legal_name' => 'New Srl'], $headers)->assertForbidden();
});

test('the API records an intercompany operation and serves the consolidated report', function (): void {
    $headers = ['X-Tenant-Id' => $this->tenantId];
    Sanctum::actingAs($this->owner);

    $created = $this->postJson('/api/v1/corporate-group/intercompany/transactions', [
        'seller_company_id' => $this->a['company']->id, 'buyer_company_id' => $this->b['company']->id,
        'transaction_date' => '2026-03-10', 'net_amount' => 1000, 'tax_amount' => 220, 'transaction_type' => 'services',
    ], $headers)->assertCreated()->assertJsonCount(4, 'data.documents');
    $id = $created->json('data.id');

    $this->postJson('/api/v1/corporate-group/intercompany/transactions', [
        'seller_company_id' => $this->a['company']->id, 'buyer_company_id' => $this->b['company']->id,
        'transaction_date' => '2026-03-10', 'net_amount' => -5,
    ], $headers)->assertUnprocessable();

    $run = $this->postJson('/api/v1/corporate-group/consolidation/runs', ['period_start' => '2026-01-01', 'period_end' => '2026-12-31'], $headers)
        ->assertCreated()->assertJsonPath('data.balance_sheet.is_balanced', true);
    $runId = $run->json('data.run.id');

    $this->getJson("/api/v1/corporate-group/consolidation/runs/{$runId}", $headers)->assertOk()->assertJsonCount(2, 'data.eliminations');
    $this->getJson('/api/v1/corporate-group/consolidation/runs', $headers)->assertOk()->assertJsonCount(1, 'data');

    $this->postJson("/api/v1/corporate-group/intercompany/transactions/{$id}/cancel", ['reversal_date' => '2026-03-20'], $headers)
        ->assertOk()->assertJsonPath('data.status', 'cancelled');
});

test('a user restricted to one company cannot run a consolidation over others', function (): void {
    $viewer = logisticsUserWithRole($this->tenantId, 'viewer');
    CompanyMember::create(['tenant_id' => $this->tenantId, 'company_id' => $this->a['company']->id, 'user_id' => $viewer->id, 'role' => 'company_admin']);
    $headers = ['X-Tenant-Id' => $this->tenantId];

    Sanctum::actingAs($viewer);
    $this->postJson('/api/v1/corporate-group/consolidation/runs', ['period_start' => '2026-01-01', 'period_end' => '2026-12-31'], $headers)->assertForbidden();
    $this->postJson('/api/v1/corporate-group/consolidation/runs', ['period_start' => '2026-01-01', 'period_end' => '2026-12-31', 'company_ids' => [$this->a['company']->id]], $headers)->assertCreated();
});

test('sites hang off a company and the intercompany accounts must belong to it', function (): void {
    $headers = ['X-Tenant-Id' => $this->tenantId];
    Sanctum::actingAs($this->owner);
    $companyId = $this->a['company']->id;

    $this->postJson("/api/v1/corporate-group/companies/{$companyId}/sites", ['code' => 'HQ', 'name' => 'Sede', 'site_type' => 'headquarters', 'city' => 'Milano'], $headers)->assertCreated();
    $this->postJson("/api/v1/corporate-group/companies/{$companyId}/sites", ['code' => 'HQ', 'name' => 'Dup'], $headers)->assertUnprocessable();
    $this->getJson("/api/v1/corporate-group/companies/{$companyId}/sites", $headers)->assertOk()->assertJsonCount(1, 'data');

    // An account of the sibling company is refused.
    $this->putJson("/api/v1/corporate-group/companies/{$companyId}/accounting-settings", ['ic_revenue_account_id' => $this->b['accounts']['4100']->id], $headers)->assertUnprocessable();
    $this->putJson("/api/v1/corporate-group/companies/{$companyId}/accounting-settings", ['ic_revenue_account_id' => $this->a['accounts']['4100']->id], $headers)->assertOk();

    expect(DB::table('company_sites')->where('company_id', $companyId)->count())->toBe(1);
});
