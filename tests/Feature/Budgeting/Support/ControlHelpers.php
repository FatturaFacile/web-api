<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Budgeting\Models\BudgetLine;
use Modules\Budgeting\Models\BudgetSpendRule;
use Modules\Budgeting\Models\BudgetVersion;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\CostCenter;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;

/*
| Shared helpers of the budget control tests (Mod 019): a company with members, ledger accounts and cost centres, an
| approved budget, actuals in the ledger and spend rules. Every function is prefixed ctl to stay clear of the helpers of
| the other budgeting tests.
*/

if (! function_exists('ctlTenant')) {
    function ctlTenant(string $name = 'Control'): Tenant
    {
        return Tenant::create(['name' => $name.' '.Str::random(4), 'legal_name' => $name.' Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    }
}

if (! function_exists('ctlMember')) {
    /**
     * @param  array<int, string>  $permissions
     */
    function ctlMember(Tenant $tenant, string $role, array $permissions = []): User
    {
        $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@budget-control.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'en']);
        $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => $permissions === [] ? null : json_encode($permissions)]);

        return $user;
    }
}

if (! function_exists('ctlAct')) {
    function ctlAct(User $user, Tenant $tenant): void
    {
        app(TenantContext::class)->setTenant($tenant);
        Sanctum::actingAs($user);
        test()->withHeaders(['X-Tenant-Id' => (string) $tenant->id]);
    }
}

if (! function_exists('ctlAccount')) {
    function ctlAccount(Tenant $tenant, string $kind = 'expense'): ChartOfAccount
    {
        return ChartOfAccount::factory()->create(['tenant_id' => $tenant->id, 'account_class' => $kind, 'account_type' => $kind, 'type' => $kind, 'normal_balance' => $kind === 'revenue' ? 'credit' : 'debit', 'name' => ucfirst($kind).' '.Str::random(4)]);
    }
}

if (! function_exists('ctlCostCenter')) {
    function ctlCostCenter(Tenant $tenant): CostCenter
    {
        return CostCenter::create(['tenant_id' => $tenant->id, 'code' => 'CC-'.Str::upper(Str::random(5)), 'name' => 'Cost center '.Str::random(4), 'is_active' => true]);
    }
}

if (! function_exists('ctlPost')) {
    /**
     * A posted journal line on an account in the ledger of the company.
     */
    function ctlPost(Tenant $tenant, ChartOfAccount $account, string $date, string $debit, ?CostCenter $costCenter = null): void
    {
        $period = FiscalPeriod::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->first() ?? FiscalPeriod::factory()->create(['tenant_id' => $tenant->id]);
        $entry = JournalEntry::factory()->create(['tenant_id' => $tenant->id, 'fiscal_period_id' => $period->id, 'posting_date' => $date, 'document_date' => $date, 'status' => 'posted']);
        JournalLine::create([
            'tenant_id' => $tenant->id,
            'journal_entry_id' => $entry->id,
            'posting_date' => $date,
            'line_number' => 1,
            'account_id' => $account->id,
            'cost_center_id' => $costCenter?->id,
            'description' => 'Actual',
            'debit_amount' => $debit,
            'credit_amount' => '0',
            'currency' => 'EUR',
            'exchange_rate' => 1,
            'amount_in_functional_currency' => $debit,
        ]);
    }
}

if (! function_exists('ctlApprovedBudget')) {
    /**
     * An approved original budget of 2028 with the same amount in every month of the account and cost centre.
     */
    function ctlApprovedBudget(Tenant $tenant, ChartOfAccount $account, ?CostCenter $costCenter, string $monthly, string $type = 'budget', string $name = 'Budget 2028'): BudgetVersion
    {
        $version = BudgetVersion::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'fiscal_year' => 2028,
            'year_start' => '2028-01-01',
            'version_type' => $type,
            'status' => BudgetVersion::Draft,
            'currency' => 'EUR',
        ]);

        foreach (range(1, 12) as $period) {
            BudgetLine::create(['tenant_id' => $tenant->id, 'version_id' => $version->id, 'account_id' => $account->id, 'cost_center_id' => $costCenter?->id, 'period' => $period, 'amount' => $monthly, 'source' => BudgetLine::Manual]);
        }

        $version->update(['status' => BudgetVersion::Approved, 'approved_at' => now()]);

        return $version;
    }
}

if (! function_exists('ctlRule')) {
    /**
     * @param  array<string, mixed>  $attributes
     */
    function ctlRule(Tenant $tenant, string $mode, array $attributes = []): BudgetSpendRule
    {
        return BudgetSpendRule::create([
            'tenant_id' => $tenant->id,
            'name' => 'Rule '.Str::random(4),
            'mode' => $mode,
            'threshold_percent' => '100',
            'document_types' => ['requisition', 'purchase_order'],
            'stages' => ['create', 'submit', 'approve'],
            'enforce_without_budget' => false,
            'priority' => 0,
            'is_active' => true,
            ...$attributes,
        ]);
    }
}
