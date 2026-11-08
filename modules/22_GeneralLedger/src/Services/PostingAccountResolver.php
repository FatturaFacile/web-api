<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use App\Models\Tenant;
use Modules\GeneralLedger\Enums\PostingAccountRole;
use Modules\GeneralLedger\Exceptions\LedgerPostingException;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\DefaultPostingAccount;

/**
 * The default posting accounts of a tenant (sales, purchases, VAT, receivables, payables, retained earnings, exchange
 * differences, rounding), for any module that needs them: Invoicing, Procurement and the closings read them here
 * instead of naming account codes of their own.
 *
 * The account set in the General Ledger settings wins; otherwise the code the tenant already stored in its preferences
 * (`accounting.accounts.*`, or `banking.ledger_defaults.*` for exchange differences) is looked up in its chart of
 * accounts. Nothing is created and no code is assumed: a role without an account resolves to null, and `require()`
 * refuses the operation.
 */
final class PostingAccountResolver
{
    /**
     * The account of a role, or null when the tenant has not chosen one.
     */
    public function account(string $tenantId, PostingAccountRole $role): ?ChartOfAccount
    {
        return $this->resolve($tenantId, $role)['account'];
    }

    /**
     * The account of a role, for an operation that cannot go on without it.
     *
     * @throws LedgerPostingException when the tenant has not chosen an account for the role
     */
    public function require(string $tenantId, PostingAccountRole $role): ChartOfAccount
    {
        return $this->account($tenantId, $role)
            ?? throw new LedgerPostingException("No default posting account is set for [{$role->value}]: choose one with PUT /api/v1/general-ledger/settings.");
    }

    /**
     * Every role with its account and where the account comes from (`settings`, `preferences` or null).
     *
     * @return array<string, array{account: ?ChartOfAccount, source: ?string}>
     */
    public function all(string $tenantId): array
    {
        $resolved = [];

        foreach (PostingAccountRole::cases() as $role) {
            $resolved[$role->value] = $this->resolve($tenantId, $role);
        }

        return $resolved;
    }

    /**
     * @return array{account: ?ChartOfAccount, source: ?string}
     */
    private function resolve(string $tenantId, PostingAccountRole $role): array
    {
        $configured = DefaultPostingAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('role', $role->value)
            ->whereNull('deleted_at')
            ->value('account_id');

        if ($configured !== null) {
            $account = ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($configured)->first();

            if ($account !== null) {
                return ['account' => $account, 'source' => 'settings'];
            }
        }

        $code = $this->preferenceCode($tenantId, $role);

        if ($code !== null) {
            $account = ChartOfAccount::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('account_code', $code)
                ->where('is_active', true)
                ->first();

            if ($account !== null) {
                return ['account' => $account, 'source' => 'preferences'];
            }
        }

        return ['account' => null, 'source' => null];
    }

    private function preferenceCode(string $tenantId, PostingAccountRole $role): ?string
    {
        $path = $role->preferencePath();

        if ($path === null) {
            return null;
        }

        $value = Tenant::find($tenantId)?->getAttribute('settings');

        foreach (['preferences', ...$path] as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
