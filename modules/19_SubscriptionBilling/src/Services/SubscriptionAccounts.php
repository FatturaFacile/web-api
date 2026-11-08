<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Services;

use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\SubscriptionBilling\Models\SubscriptionPlan;

/**
 * Ledger accounts of the subscription postings. Invoices of a subscription credit the contract liability (deferred
 * revenue, 2300 Ratei e risconti passivi) and the monthly recognition moves it to revenue (4000). A plan may name its
 * own accounts of the tenant. Missing default accounts are created on first use, like the posting listeners do.
 */
final class SubscriptionAccounts
{
    public const string DEFERRED_REVENUE_CODE = '2300';

    public const string REVENUE_CODE = '4000';

    public const string SALES_RETURNS_CODE = '4090';

    /**
     * Contract liability credited by the invoices of the plan.
     */
    public function deferredRevenue(string $tenantId, ?SubscriptionPlan $plan = null): ChartOfAccount
    {
        return $this->planAccount($tenantId, $plan?->deferred_revenue_account_id)
            ?? $this->account($tenantId, self::DEFERRED_REVENUE_CODE, 'Deferred revenue (Ratei e risconti passivi)', 'liability', 'credit');
    }

    /**
     * Revenue account credited when the deferred revenue of the plan is recognized.
     */
    public function revenue(string $tenantId, ?SubscriptionPlan $plan = null): ChartOfAccount
    {
        return $this->planAccount($tenantId, $plan?->revenue_account_id)
            ?? $this->account($tenantId, self::REVENUE_CODE, 'Sales Revenue (Ricavi delle Vendite)', 'revenue', 'credit');
    }

    /**
     * Contra-revenue account the credit notes debit (the account CreditNoteAccountingListener posts to).
     */
    public function salesReturns(string $tenantId): ChartOfAccount
    {
        return $this->account($tenantId, self::SALES_RETURNS_CODE, 'Sales Returns & Allowances (Resi su Vendite)', 'revenue', 'debit');
    }

    /**
     * Whether an account id belongs to the tenant (plan accounts given by the API).
     */
    public function belongsToTenant(string $tenantId, string $accountId): bool
    {
        return ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($accountId)->exists();
    }

    private function planAccount(string $tenantId, ?string $accountId): ?ChartOfAccount
    {
        if ($accountId === null || $accountId === '') {
            return null;
        }

        return ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($accountId)->first();
    }

    private function account(string $tenantId, string $code, string $name, string $type, string $normalBalance): ChartOfAccount
    {
        return ChartOfAccount::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenantId, 'account_code' => $code],
            [
                'id' => (string) Str::uuid7(),
                'name' => $name,
                'account_class' => $type,
                'account_type' => $type,
                'normal_balance' => $normalBalance,
                'currency' => TenantAccountingCurrency::of($tenantId),
                'is_active' => true,
            ]
        );
    }
}
