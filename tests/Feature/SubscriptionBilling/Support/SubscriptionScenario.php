<?php

declare(strict_types=1);

namespace Tests\Feature\SubscriptionBilling\Support;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\SubscriptionBilling\Models\SubscriptionPlan;

/**
 * Builders shared by the subscription engine tests: a company with open fiscal years, users by role, customers,
 * plans and ledger balances.
 */
final class SubscriptionScenario
{
    /**
     * A company with the fiscal years 2026 and 2027 open, set as the current tenant.
     */
    public static function company(string $name = 'Abbonamenti Srl', string $country = 'IT'): Tenant
    {
        $tenant = Tenant::create(['name' => $name, 'legal_name' => $name, 'vat_number' => '0'.random_int(1000000000, 9999999999), 'country' => $country, 'default_currency' => $country === 'CH' ? 'CHF' : 'EUR']);
        app(OpenFiscalYearAction::class)->execute((string) $tenant->id, 2026);
        app(OpenFiscalYearAction::class)->execute((string) $tenant->id, 2027);
        app(TenantContext::class)->setTenant($tenant);

        return $tenant;
    }

    /**
     * A user with a role in the company, authenticated for the next requests.
     */
    public static function actingAs(Tenant $tenant, string $role = 'admin'): User
    {
        $user = User::create(['name' => ucfirst($role), 'email' => Str::random(12).'@example.it', 'password' => bcrypt('secret123'), 'locale' => 'en']);
        $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);
        Sanctum::actingAs($user);
        test()->withHeader('X-Tenant-Id', (string) $tenant->id);

        return $user;
    }

    public static function customer(Tenant $tenant, ?string $email = null): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Cliente '.Str::random(5).' SpA',
            'type' => 'customer',
            'country' => 'IT',
            'email' => $email,
            'payment_terms_days' => 30,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function plan(Tenant $tenant, array $overrides = []): SubscriptionPlan
    {
        return SubscriptionPlan::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $tenant->id,
            'plan_code' => 'PLAN-'.strtoupper(Str::random(6)),
            'name' => 'Cloud Basic',
            'billing_interval' => 'monthly',
            'interval_count' => 1,
            'pricing_model' => 'flat_fee',
            'currency' => 'EUR',
            'setup_fee' => '0.00',
            'base_price' => '100.0000',
            'tax_rate' => '22.00',
            'trial_period_days' => 0,
            'min_commitment_cycles' => 1,
            'billing_alignment' => 'anniversary',
            'renewal_price_increase_percent' => '0',
            'is_active' => true,
        ], $overrides));
    }

    /**
     * Balance (debit - credit) of an account code in the posted journal of the company, as a decimal string.
     */
    public static function balance(Tenant $tenant, string $accountCode): string
    {
        $account = ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('account_code', $accountCode)->first();

        if ($account === null) {
            return '0.00';
        }

        $entries = JournalEntry::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status', 'posted')->pluck('id');
        $lines = JournalLine::withoutGlobalScopes()->whereIn('journal_entry_id', $entries)->where('account_id', $account->id)->get(['debit_amount', 'credit_amount']);
        $total = '0';

        foreach ($lines as $line) {
            $total = bcadd($total, bcsub((string) $line->debit_amount, (string) $line->credit_amount, 4), 4);
        }

        return bcadd($total, '0', 2);
    }
}
