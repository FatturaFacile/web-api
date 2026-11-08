<?php

declare(strict_types=1);

namespace App\Services\Banking;

use App\Models\BankStatementLine;
use Modules\Treasury\Services\TreasuryLedgerAccounts;

/**
 * Tenant preferences that drive the automatic reconciliation: `banking.reconciliation.*` (matching) and
 * `banking.ledger_defaults.*` (counter accounts of the categories and automatic categorisation of outflows without an
 * invoice). No ledger account is shipped by default: a category without its configured account cannot be posted.
 */
final readonly class ReconciliationSettings
{
    public const DEFAULT_TOLERANCE = 0.05;

    public const DEFAULT_MAX_FEE = 15.0;

    /**
     * The preference of `banking.ledger_defaults` holding the counter account of each non-invoice category.
     *
     * @var array<string, string>
     */
    public const CATEGORY_SETTINGS = [
        BankStatementLine::CATEGORY_TAX_PAYMENT => 'tax_account_code',
        BankStatementLine::CATEGORY_PAYROLL => 'payroll_account_code',
        BankStatementLine::CATEGORY_BANK_EXPENSE => 'bank_charges_account_code',
        BankStatementLine::CATEGORY_INTERNAL_TRANSFER => 'internal_transfer_account_code',
        BankStatementLine::CATEGORY_GENERIC_EXPENSE => 'generic_expense_account_code',
    ];

    /**
     * @param  array<string, string>  $categoryAccounts  category => configured account code
     */
    public function __construct(
        public float $tolerance = self::DEFAULT_TOLERANCE,
        public float $maxFee = self::DEFAULT_MAX_FEE,
        public bool $allowPartial = true,
        public array $categoryAccounts = [],
        public bool $autoCategorize = true,
    ) {}

    /**
     * The settings stored in the preferences of the tenant.
     */
    public static function forTenant(string $tenantId): self
    {
        $banking = TreasuryLedgerAccounts::preference($tenantId, 'banking');
        $value = static fn (string $group, string $key): mixed => is_array($banking) && is_array($banking[$group] ?? null) ? ($banking[$group][$key] ?? null) : null;
        $accounts = [];

        foreach (self::CATEGORY_SETTINGS as $category => $setting) {
            $code = $value('ledger_defaults', $setting);

            if (is_string($code) && $code !== '') {
                $accounts[$category] = $code;
            }
        }

        return new self(
            tolerance: (float) ($value('reconciliation', 'auto_match_tolerance_amount') ?? self::DEFAULT_TOLERANCE),
            maxFee: (float) ($value('reconciliation', 'max_fee_deduction_amount') ?? self::DEFAULT_MAX_FEE),
            allowPartial: (bool) ($value('reconciliation', 'allow_partial_reconciliation') ?? true),
            categoryAccounts: $accounts,
            autoCategorize: (bool) ($value('ledger_defaults', 'auto_categorize_standard_codes') ?? true),
        );
    }

    /**
     * The configured counter account of a non-invoice category, null when the tenant has not configured it.
     */
    public function accountFor(string $category): ?string
    {
        return $this->categoryAccounts[$category] ?? null;
    }

    /**
     * The name of the preference that configures the account of a category (for the error messages).
     */
    public static function settingFor(string $category): string
    {
        return self::CATEGORY_SETTINGS[$category] ?? 'generic_expense_account_code';
    }
}
