<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\Treasury\Models\BankAccount;

/**
 * The accounts of the postings between documents and cash. They use the codes the posting listeners already write to,
 * created on first use. A bank account of Treasury linked to a ledger account takes precedence over the default.
 */
final class LedgerAccountResolver
{
    public function receivables(string $tenantId): ChartOfAccount
    {
        return $this->controlAccount($tenantId, '1100', 'Accounts Receivable (Crediti v/Clienti)', 'asset', 'debit', 'customer');
    }

    public function payables(string $tenantId): ChartOfAccount
    {
        return $this->controlAccount($tenantId, '2000', 'Accounts Payable (Debiti v/Fornitori)', 'liability', 'credit', 'supplier');
    }

    public function bankCharges(string $tenantId): ChartOfAccount
    {
        return $this->account($tenantId, '6800', 'Bank charges (Spese bancarie)', 'expense', 'debit');
    }

    public function rounding(string $tenantId): ChartOfAccount
    {
        return $this->account($tenantId, '6960', 'Rounding differences (Arrotondamenti)', 'expense', 'debit');
    }

    public function cash(string $tenantId): ChartOfAccount
    {
        return $this->account($tenantId, '1000', 'Cash (Cassa)', 'asset', 'debit');
    }

    /**
     * The ledger account of the tenant's bank account in that currency: the one on the statement when its IBAN is
     * known, else the default one.
     */
    public function bank(string $tenantId, ?string $currency = null, ?string $iban = null): ChartOfAccount
    {
        $accounts = BankAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereNotNull('gl_account_id')
            ->orderByDesc('is_default')
            ->get();

        $normalized = strtoupper(str_replace(' ', '', (string) $iban));
        $linked = $accounts->first(static fn (BankAccount $account): bool => $normalized !== '' && strtoupper(str_replace(' ', '', (string) $account->iban)) === $normalized)
            ?? $accounts->first(static fn (BankAccount $account): bool => $currency !== null && $account->currency === $currency)
            ?? $accounts->first();

        if ($linked !== null) {
            $account = ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($linked->gl_account_id)->first();

            if ($account !== null) {
                return $account;
            }
        }

        return $this->controlAccount($tenantId, '1020', 'Bank (Banca c/c)', 'asset', 'debit', 'bank');
    }

    private function controlAccount(string $tenantId, string $code, string $name, string $type, string $normalBalance, string $reconciliationType): ChartOfAccount
    {
        $account = $this->account($tenantId, $code, $name, $type, $normalBalance);

        if ($account->reconciliation_type !== $reconciliationType) {
            $account->update(['is_reconciliation_account' => true, 'reconciliation_type' => $reconciliationType]);
        }

        return $account;
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
