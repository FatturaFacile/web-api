<?php

declare(strict_types=1);

namespace Modules\LeaseAccounting\Services;

use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\LeaseAccounting\Models\LeaseContract;

/**
 * The ledger accounts of the lease postings. A contract that names an account of its own uses it; otherwise the
 * default code of config/leases.php is used, created in the chart of accounts of the tenant on first use, like the
 * posting services of the other modules do.
 */
final class LeaseAccountResolver
{
    /** Roles a contract can override, with the column that holds the account. */
    private const array ContractColumns = [
        'rou_asset' => 'rou_asset_account_id',
        'accumulated_depreciation' => 'accumulated_depreciation_account_id',
        'lease_liability' => 'lease_liability_account_id',
        'interest_expense' => 'interest_expense_account_id',
        'depreciation_expense' => 'depreciation_expense_account_id',
    ];

    /**
     * @param  'rou_asset'|'accumulated_depreciation'|'lease_liability'|'lease_clearing'|'interest_expense'|'depreciation_expense'|'remeasurement_result'  $role
     */
    public function forContract(LeaseContract $contract, string $role): ChartOfAccount
    {
        $tenantId = (string) $contract->tenant_id;
        $column = self::ContractColumns[$role] ?? null;
        $accountId = $column !== null ? $contract->getAttribute($column) : null;

        if ($accountId !== null) {
            $account = ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($accountId)->first();

            if ($account !== null) {
                return $account;
            }
        }

        /** @var array{code: string, name: string, type: string, normal_balance: string} $default */
        $default = config("leases.accounts.{$role}");

        return ChartOfAccount::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenantId, 'account_code' => $default['code']],
            [
                'id' => (string) Str::uuid7(),
                'name' => $default['name'],
                'account_class' => $default['type'],
                'account_type' => $default['type'],
                'normal_balance' => $default['normal_balance'],
                'currency' => TenantAccountingCurrency::of($tenantId),
                'is_active' => true,
            ]
        );
    }
}
