<?php

declare(strict_types=1);

namespace Modules\Treasury\Services;

use App\Models\Tenant;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\Treasury\Exceptions\TreasuryOperationException;
use Modules\Treasury\Models\BankAccount;

/**
 * The ledger accounts of the treasury postings. Counter accounts come from the tenant preferences
 * `banking.ledger_defaults.*` and must exist in the chart of accounts: nothing is invented, a missing or unknown account
 * refuses the posting with a 422. A bank account posts on the ledger account it is linked to.
 */
final class TreasuryLedgerAccounts
{
    /**
     * The account configured under `banking.ledger_defaults.{$setting}`.
     *
     * @throws TreasuryOperationException when the preference is empty or names an account outside the chart
     */
    public function configured(string $tenantId, string $setting, string $field): ChartOfAccount
    {
        $code = $this->configuredCode($tenantId, $setting);

        if ($code === null) {
            throw new TreasuryOperationException(__('banking.errors.ledger_account_not_configured', ['setting' => $setting]), $field);
        }

        return $this->byCode($tenantId, $code, $field);
    }

    /**
     * The code configured under `banking.ledger_defaults.{$setting}`, null when unset.
     */
    public function configuredCode(string $tenantId, string $setting): ?string
    {
        $code = self::preference($tenantId, 'banking', 'ledger_defaults', $setting);

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * A value of the preferences of a tenant (`settings.preferences`) by its path, null when it is not set.
     */
    public static function preference(string $tenantId, string ...$path): mixed
    {
        $value = Tenant::find($tenantId)?->getAttribute('settings');

        foreach (['preferences', ...$path] as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * An active account of the chart of accounts of the tenant by its code.
     *
     * @throws TreasuryOperationException when the tenant has no such account
     */
    public function byCode(string $tenantId, string $code, string $field): ChartOfAccount
    {
        return ChartOfAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('account_code', $code)
            ->where('is_active', true)
            ->first()
            ?? throw new TreasuryOperationException(__('banking.errors.ledger_account_unknown', ['code' => $code]), $field);
    }

    /**
     * An active account of the chart of accounts of the tenant by its identifier.
     *
     * @throws TreasuryOperationException when the tenant has no such account
     */
    public function byId(string $tenantId, string $accountId, string $field): ChartOfAccount
    {
        return ChartOfAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($accountId)
            ->where('is_active', true)
            ->first()
            ?? throw new TreasuryOperationException(__('banking.errors.ledger_account_unknown', ['code' => $accountId]), $field);
    }

    /**
     * The ledger account a treasury bank account is linked to.
     *
     * @throws TreasuryOperationException when the bank account has no ledger account
     */
    public function bank(BankAccount $account, string $field): ChartOfAccount
    {
        $ledgerAccount = $account->gl_account_id === null ? null : ChartOfAccount::withoutGlobalScopes()
            ->where('tenant_id', $account->tenant_id)
            ->whereKey($account->gl_account_id)
            ->first();

        return $ledgerAccount ?? throw new TreasuryOperationException(__('banking.errors.bank_account_not_linked', ['iban' => (string) $account->iban]), $field);
    }
}
