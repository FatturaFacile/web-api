<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use App\Models\Tenant;
use App\Services\Tenancy\TenantPreferences;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Enums\PostingAccountRole;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\Treasury\Models\BankAccount;

/**
 * The accounts of the postings between documents and cash. A role the tenant configured in the General Ledger settings
 * (PostingAccountResolver: `gl_default_posting_accounts`, then the accounting preferences) wins; otherwise the codes the
 * posting listeners always wrote to are used, created on first use, so a tenant that configured nothing keeps posting
 * exactly as before. A bank account of Treasury linked to a ledger account takes precedence over the default.
 */
final class LedgerAccountResolver
{
    public function __construct(private readonly PostingAccountResolver $postingAccounts = new PostingAccountResolver) {}

    /**
     * The customer control account: the configured receivables account, else 1100.
     */
    public function receivables(string $tenantId): ChartOfAccount
    {
        return $this->configuredControlAccount($tenantId, PostingAccountRole::Receivables, 'customer')
            ?? $this->controlAccount($tenantId, '1100', 'Accounts Receivable (Crediti v/Clienti)', 'asset', 'debit', 'customer');
    }

    /**
     * The supplier control account: the configured payables account, else 2000.
     */
    public function payables(string $tenantId): ChartOfAccount
    {
        return $this->configuredControlAccount($tenantId, PostingAccountRole::Payables, 'supplier')
            ?? $this->controlAccount($tenantId, '2000', 'Accounts Payable (Debiti v/Fornitori)', 'liability', 'credit', 'supplier');
    }

    /**
     * The revenue of a sales line that names no account of its own: the configured sales revenue account, else 4000.
     */
    public function salesRevenue(string $tenantId): ChartOfAccount
    {
        return $this->postingAccounts->account($tenantId, PostingAccountRole::SalesRevenue)
            ?? $this->account($tenantId, '4000', 'Sales Revenue (Ricavi delle Vendite)', 'revenue', 'credit');
    }

    /**
     * The cost of a supplier invoice not matched to a goods receipt: the configured purchase expense account, else 6000.
     */
    public function purchaseExpense(string $tenantId): ChartOfAccount
    {
        return $this->postingAccounts->account($tenantId, PostingAccountRole::PurchaseExpense)
            ?? $this->account($tenantId, '6000', 'Purchases and services (Acquisti e servizi)', 'expense', 'debit');
    }

    public function bankCharges(string $tenantId): ChartOfAccount
    {
        return $this->account($tenantId, '6800', 'Bank charges (Spese bancarie)', 'expense', 'debit');
    }

    /**
     * Cash rounding and small payment differences: the configured rounding account, else 6960.
     */
    public function rounding(string $tenantId): ChartOfAccount
    {
        return $this->postingAccounts->account($tenantId, PostingAccountRole::Rounding)
            ?? $this->account($tenantId, '6960', 'Rounding differences (Arrotondamenti)', 'expense', 'debit');
    }

    /**
     * Withholding the customers kept on our invoices: a credit towards the tax authority until the tax return.
     */
    public function withholdingReceivable(string $tenantId): ChartOfAccount
    {
        return $this->account($tenantId, '1260', 'Erario c/ritenute subite (Withholding tax receivable)', 'asset', 'debit');
    }

    /**
     * Withholding kept on supplier invoices, owed to the tax authority with the F24.
     */
    public function withholdingPayable(string $tenantId): ChartOfAccount
    {
        return $this->account($tenantId, '2230', 'Erario c/ritenute da versare (Withholding tax payable)', 'liability', 'credit');
    }

    /**
     * Contributions kept on supplier invoices (Enasarco, INPS and the other funds), owed to the fund.
     */
    public function contributionsPayable(string $tenantId): ChartOfAccount
    {
        return $this->account($tenantId, '2235', 'Debiti v/enti previdenziali (Contributions payable)', 'liability', 'credit');
    }

    public function stampDutyPayable(string $tenantId): ChartOfAccount
    {
        return $this->account($tenantId, '2240', 'Erario c/imposta di bollo (Stamp duty payable)', 'liability', 'credit');
    }

    public function stampDutyExpense(string $tenantId): ChartOfAccount
    {
        return $this->account($tenantId, '6810', 'Imposta di bollo (Stamp duty expense)', 'expense', 'debit');
    }

    /**
     * What a professional fund charges through the invoice and is owed to the fund.
     */
    public function pensionFundPayable(string $tenantId): ChartOfAccount
    {
        return $this->account($tenantId, '2245', 'Debiti v/casse previdenziali (Pension fund payable)', 'liability', 'credit');
    }

    /**
     * The INPS rivalsa, which stays with the professional as income.
     */
    public function pensionFundRecharge(string $tenantId): ChartOfAccount
    {
        return $this->account($tenantId, '4095', 'Rivalsa INPS (INPS recharge income)', 'revenue', 'credit');
    }

    /**
     * The share of the Enasarco contribution the tenant pays as a principal on the commissions of its agents.
     */
    public function enasarcoPrincipalExpense(string $tenantId): ChartOfAccount
    {
        return $this->account($tenantId, '6475', 'Contributi Enasarco a carico mandante (Enasarco principal contributions)', 'expense', 'debit');
    }

    public function enasarcoExpense(string $tenantId): ChartOfAccount
    {
        return $this->account($tenantId, '6470', 'Contributi Enasarco (Enasarco contributions)', 'expense', 'debit');
    }

    /**
     * VAT of a self-invoice the tenant owes without being able to recover it (TD21, TD27).
     */
    public function selfInvoiceVatCost(string $tenantId): ChartOfAccount
    {
        return $this->account($tenantId, '6975', 'IVA su autofatture (VAT on self-invoices)', 'expense', 'debit');
    }

    /**
     * Output VAT of sales documents: the configured output VAT account, else 2200.
     */
    public function outputVat(string $tenantId): ChartOfAccount
    {
        return $this->postingAccounts->account($tenantId, PostingAccountRole::OutputVat)
            ?? $this->account($tenantId, '2200', 'IVA c/Vendite (Sales Output VAT)', 'liability', 'credit');
    }

    /**
     * Input VAT of purchase documents: the configured input VAT account, else 1400.
     */
    public function inputVat(string $tenantId): ChartOfAccount
    {
        return $this->postingAccounts->account($tenantId, PostingAccountRole::InputVat)
            ?? $this->account($tenantId, '1400', 'IVA c/Acquisti (Purchase VAT)', 'asset', 'debit');
    }

    public function cash(string $tenantId): ChartOfAccount
    {
        return $this->account($tenantId, '1000', 'Cash (Cassa)', 'asset', 'debit');
    }

    /**
     * The ledger account of the tenant's bank account in that currency: the Treasury bank account with the IBAN of the
     * movement, else the account code mapped to that IBAN in the tenant preference `accounting.bank_accounts`, else the
     * Treasury bank account in that currency, else the default one.
     */
    public function bank(string $tenantId, ?string $currency = null, ?string $iban = null): ChartOfAccount
    {
        $accounts = BankAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereNotNull('gl_account_id')
            ->orderByDesc('is_default')
            ->get();

        $normalized = TenantPreferences::normalizeIban((string) $iban);
        $byIban = $accounts->first(static fn (BankAccount $account): bool => $normalized !== '' && TenantPreferences::normalizeIban((string) $account->iban) === $normalized);

        if ($byIban === null && $normalized !== '') {
            $settings = Tenant::find($tenantId)?->getAttribute('settings');
            $mapping = is_array($settings) ? ($settings['preferences']['accounting']['bank_accounts'] ?? null) : null;
            $mappedCode = collect(is_array($mapping) ? $mapping : [])
                ->mapWithKeys(static fn (mixed $code, string|int $mappedIban): array => [TenantPreferences::normalizeIban((string) $mappedIban) => $code])
                ->get($normalized);

            if (is_string($mappedCode) && $mappedCode !== '') {
                return $this->controlAccount($tenantId, $mappedCode, "Bank {$normalized}", 'asset', 'debit', 'bank');
            }
        }

        $linked = $byIban
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

    /**
     * The configured account of a control role, flagged as a reconciliation account of the partner type as the default
     * control accounts are, so open items and partner balances work on it the same way.
     */
    private function configuredControlAccount(string $tenantId, PostingAccountRole $role, string $reconciliationType): ?ChartOfAccount
    {
        $account = $this->postingAccounts->account($tenantId, $role);

        if ($account !== null && ($account->reconciliation_type !== $reconciliationType || ! $account->is_reconciliation_account)) {
            $account->update(['is_reconciliation_account' => true, 'reconciliation_type' => $reconciliationType]);
        }

        return $account;
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
