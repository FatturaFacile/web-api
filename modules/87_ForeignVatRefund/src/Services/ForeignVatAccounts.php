<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Services;

use Illuminate\Support\Str;
use Modules\ForeignVatRefund\Models\ForeignVatSetting;
use Modules\GeneralLedger\Enums\PostingAccountRole;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Services\PostingAccountResolver;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;

/**
 * The ledger accounts of the module: the transitory asset account "foreign VAT receivable" and the expense account of the foreign
 * VAT that stays a cost. A company chooses them in the settings; when it did not, they are created in its chart of accounts on first use.
 */
final class ForeignVatAccounts
{
    public function __construct(private readonly PostingAccountResolver $roles = new PostingAccountResolver) {}

    public function settings(string $tenantId): ForeignVatSetting
    {
        return ForeignVatSetting::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at')->first()
            ?? ForeignVatSetting::query()->create(['tenant_id' => $tenantId, 'auto_intercept' => true]);
    }

    public function receivable(string $tenantId): ChartOfAccount
    {
        $setting = $this->settings($tenantId);
        $account = $this->owned($tenantId, $setting->receivable_account_id)
            ?? $this->ensure($tenantId, (string) config('foreign_vat_refund.accounts.receivable_code'), 'Foreign VAT refundable (transitory)', 'asset', 'debit');

        if ($setting->receivable_account_id !== $account->id) {
            $setting->update(['receivable_account_id' => $account->id]);
        }

        return $account;
    }

    public function nonRecoverableExpense(string $tenantId): ChartOfAccount
    {
        $setting = $this->settings($tenantId);
        $account = $this->owned($tenantId, $setting->non_recoverable_expense_account_id)
            ?? $this->ensure($tenantId, (string) config('foreign_vat_refund.accounts.non_recoverable_expense_code'), 'Non-recoverable foreign VAT (indetraibile / sopravvenienza passiva)', 'expense', 'debit');

        if ($setting->non_recoverable_expense_account_id !== $account->id) {
            $setting->update(['non_recoverable_expense_account_id' => $account->id]);
        }

        return $account;
    }

    public function role(string $tenantId, PostingAccountRole $role): ?ChartOfAccount
    {
        return $this->roles->account($tenantId, $role);
    }

    public function owned(string $tenantId, ?string $accountId): ?ChartOfAccount
    {
        if ($accountId === null || $accountId === '') {
            return null;
        }

        return ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($accountId)->first();
    }

    private function ensure(string $tenantId, string $code, string $name, string $type, string $normalBalance): ChartOfAccount
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
            ],
        );
    }
}
