<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Tenant;
use App\Services\Tenancy\TenantPreferences;
use Illuminate\Support\Facades\DB;

/**
 * The ledger accounts used by the general ledger export: codes from the tenant preferences (`accounting.accounts.*`
 * and `banking.ledger_defaults.*`) with defaults, and names from the chart of accounts of the tenant when it defines
 * the code, otherwise the standard name.
 */
final class LedgerAccounts
{
    public const DEFAULTS = [
        'receivables' => '1200',
        'payables' => '2000',
        'revenue' => '4000',
        'output_vat' => '2600',
        'cost' => '3000',
        'input_vat' => '1600',
        'bank' => '1000',
        'rounding' => '7900',
        'tax_payment' => '6800',
        'payroll' => '6000',
        'bank_expense' => '6220',
        'generic_expense' => '6900',
        'transfer_clearing' => '1090',
    ];

    /**
     * @param  array<string, string>  $codes  role => account code
     * @param  array<string, string>  $chartNames  account code => name of the tenant chart of accounts
     * @param  array<string, string>  $bankAccounts  IBAN (no spaces, upper case) => ledger account code of that bank account
     */
    private function __construct(private readonly array $codes, private readonly array $chartNames, private readonly array $bankAccounts = []) {}

    public static function forTenant(string $tenantId): self
    {
        $preferences = Tenant::find($tenantId)?->settings['preferences'] ?? [];
        $accounting = $preferences['accounting'] ?? [];
        $accounts = $accounting['accounts'] ?? [];
        $ledger = $preferences['banking']['ledger_defaults'] ?? [];

        $codes = self::DEFAULTS;

        foreach (['receivables', 'payables', 'revenue', 'output_vat', 'cost', 'input_vat', 'bank', 'rounding'] as $role) {
            $codes[$role] = (string) ($accounts["{$role}_account_code"] ?? self::DEFAULTS[$role]);
        }

        $codes['tax_payment'] = (string) ($ledger['tax_account_code'] ?? self::DEFAULTS['tax_payment']);
        $codes['payroll'] = (string) ($ledger['payroll_account_code'] ?? self::DEFAULTS['payroll']);
        $codes['bank_expense'] = (string) ($ledger['bank_charges_account_code'] ?? self::DEFAULTS['bank_expense']);

        $names = DB::table('chart_of_accounts')->where('tenant_id', $tenantId)->whereNull('deleted_at')->pluck('name', 'code')->all();

        $bankAccounts = collect($accounting['bank_accounts'] ?? [])->mapWithKeys(fn (mixed $code, string|int $iban): array => [TenantPreferences::normalizeIban((string) $iban) => (string) $code])->all();

        return new self($codes, $names, $bankAccounts);
    }

    /**
     * The account code of a role (`receivables`, `bank`, `tax_payment`, ...).
     */
    public function code(string $role): string
    {
        return $this->codes[$role];
    }

    /**
     * The ledger account of a bank account: the one mapped to its IBAN in `accounting.bank_accounts`, otherwise the
     * default bank account (also used when the IBAN is unknown).
     */
    public function bankCode(?string $iban): string
    {
        return ($iban === null || $iban === '' ? null : ($this->bankAccounts[TenantPreferences::normalizeIban($iban)] ?? null)) ?? $this->codes['bank'];
    }

    /**
     * The name of an account: from the chart of accounts, else the standard name of the role that uses the code, else
     * a generic label.
     */
    public function name(string $code): string
    {
        if (isset($this->chartNames[$code])) {
            return (string) $this->chartNames[$code];
        }

        $iban = array_search($code, $this->bankAccounts, true);

        if ($iban !== false && $code !== $this->codes['bank']) {
            return __('accounting.accounts.bank_account', ['iban' => $iban]);
        }

        $role = array_search($code, $this->codes, true);

        return __('accounting.accounts.'.($role === false ? 'other' : $role));
    }
}
