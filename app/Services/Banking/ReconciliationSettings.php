<?php

declare(strict_types=1);

namespace App\Services\Banking;

use App\Models\Tenant;

/**
 * Tenant preferences that drive the automatic reconciliation: `banking.reconciliation.*` (matching) and
 * `banking.ledger_defaults.*` (accounts and automatic categorisation of outflows without an invoice).
 */
final readonly class ReconciliationSettings
{
    public const DEFAULT_TOLERANCE = 0.05;

    public const DEFAULT_MAX_FEE = 15.0;

    public const DEFAULT_TAX_ACCOUNT = '6800';

    public const DEFAULT_PAYROLL_ACCOUNT = '6000';

    public const DEFAULT_BANK_CHARGES_ACCOUNT = '6220';

    public function __construct(
        public float $tolerance = self::DEFAULT_TOLERANCE,
        public float $maxFee = self::DEFAULT_MAX_FEE,
        public bool $allowPartial = true,
        public string $taxAccountCode = self::DEFAULT_TAX_ACCOUNT,
        public string $payrollAccountCode = self::DEFAULT_PAYROLL_ACCOUNT,
        public string $bankChargesAccountCode = self::DEFAULT_BANK_CHARGES_ACCOUNT,
        public bool $autoCategorize = true,
    ) {}

    public static function forTenant(string $tenantId): self
    {
        $banking = Tenant::find($tenantId)?->settings['preferences']['banking'] ?? [];
        $matching = $banking['reconciliation'] ?? [];
        $ledger = $banking['ledger_defaults'] ?? [];

        return new self(
            tolerance: (float) ($matching['auto_match_tolerance_amount'] ?? self::DEFAULT_TOLERANCE),
            maxFee: (float) ($matching['max_fee_deduction_amount'] ?? self::DEFAULT_MAX_FEE),
            allowPartial: (bool) ($matching['allow_partial_reconciliation'] ?? true),
            taxAccountCode: (string) ($ledger['tax_account_code'] ?? self::DEFAULT_TAX_ACCOUNT),
            payrollAccountCode: (string) ($ledger['payroll_account_code'] ?? self::DEFAULT_PAYROLL_ACCOUNT),
            bankChargesAccountCode: (string) ($ledger['bank_charges_account_code'] ?? self::DEFAULT_BANK_CHARGES_ACCOUNT),
            autoCategorize: (bool) ($ledger['auto_categorize_standard_codes'] ?? true),
        );
    }

    /**
     * The default ledger account of a non-invoice category, null when the category has none.
     */
    public function accountFor(string $category): ?string
    {
        return match ($category) {
            'tax_payment' => $this->taxAccountCode,
            'payroll' => $this->payrollAccountCode,
            'bank_expense' => $this->bankChargesAccountCode,
            default => null,
        };
    }
}
