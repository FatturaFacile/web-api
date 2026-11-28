<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Domain\Dscr;

use Carbon\CarbonImmutable;

/**
 * The cash movements of one month of the horizon, split the way the DSCR needs them (amounts are positive decimal strings).
 *
 * - inflows: every expected cash receipt;
 * - operatingOutflows: ordinary outflows (payroll, suppliers, orders, other) without financial debt, tax and capex;
 * - financialDebt: loan principal and interest and lease payments;
 * - taxSocial: tax, VAT and social security payments;
 * - overdueSuppliers: supplier invoices already overdue, to be repaid (first month only);
 * - capex: investments.
 */
final readonly class MonthlyBucket
{
    public function __construct(
        public CarbonImmutable $month,
        public string $inflows = '0',
        public string $operatingOutflows = '0',
        public string $financialDebt = '0',
        public string $taxSocial = '0',
        public string $overdueSuppliers = '0',
        public string $capex = '0',
    ) {}
}
