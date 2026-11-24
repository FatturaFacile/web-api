<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectRateCard;

/**
 * Two companies, an open fiscal calendar for the current year, a customer and an owner acting on the first company.
 */
function projectsSetUp(object $test): void
{
    $test->tenant = Tenant::create(['name' => 'Studio Alfa', 'legal_name' => 'Studio Alfa Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $test->otherTenant = Tenant::create(['name' => 'Studio Beta', 'legal_name' => 'Studio Beta Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($test->tenant);

    fiscalCalendar($test->tenant, (int) now()->year);

    $test->contact = Contact::factory()->create(['tenant_id' => $test->tenant->id, 'type' => 'customer', 'company_name' => 'Cliente Spa', 'vat_number' => 'IT01234567890']);
    $test->owner = projectsUser($test, 'owner');
    $test->manager = projectsUser($test, 'project_manager');
    $test->member = projectsUser($test, 'member');
    $test->reader = projectsUser($test, 'accountant');
    projectsActAs($test, $test->owner);
}

/**
 * A user who is a member of the company with the given role.
 */
function projectsUser(object $test, string $role, ?Tenant $tenant = null): User
{
    $tenant ??= $test->tenant;
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::uuid().'@example.test', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode([])]);

    return $user;
}

function projectsActAs(object $test, User $user, ?Tenant $tenant = null): void
{
    $tenant ??= $test->tenant;
    Sanctum::actingAs($user);
    $test->withHeader('X-Tenant-Id', $tenant->id);
    app(TenantContext::class)->setTenant($tenant);
}

/**
 * An active project managed by the project manager of the test company.
 *
 * @param  array<string, mixed>  $attributes
 */
function projectsProject(object $test, array $attributes = []): Project
{
    return Project::factory()->create([
        'tenant_id' => $test->tenant->id,
        'company_id' => $test->tenant->id,
        'contact_id' => $test->contact->id,
        'project_manager_user_id' => $test->manager->id,
        'status' => 'active',
        'contract_value' => '100000.00',
        'planned_start_date' => now()->subMonth()->toDateString(),
        'planned_end_date' => now()->addMonths(5)->toDateString(),
        'revenue_recognition_method' => null,
        'billing_method' => 'fixed_price',
        'project_type' => 'construction',
        ...$attributes,
    ]);
}

function projectsRateCard(object $test, User $user, string $cost = '40.00', string $bill = '90.00'): ProjectRateCard
{
    return ProjectRateCard::create(['tenant_id' => $test->tenant->id, 'user_id' => $user->id, 'cost_rate' => $cost, 'bill_rate' => $bill]);
}

/**
 * Debit minus credit of the journal lines of a project on an account code.
 */
function projectsLedgerBalance(string $tenantId, string $projectId, string $accountCode): string
{
    $lines = JournalLine::withoutGlobalScopes()
        ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
        ->where('journal_lines.tenant_id', $tenantId)
        ->where('journal_lines.project_id', $projectId)
        ->where('chart_of_accounts.account_code', $accountCode)
        ->whereNull('journal_lines.deleted_at')
        ->get(['journal_lines.debit_amount', 'journal_lines.credit_amount']);

    $balance = '0';

    foreach ($lines as $line) {
        $balance = bcadd($balance, bcsub((string) $line->getAttribute('debit_amount'), (string) $line->getAttribute('credit_amount'), 4), 4);
    }

    return bcadd($balance, '0', 2);
}
