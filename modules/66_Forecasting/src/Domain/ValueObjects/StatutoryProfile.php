<?php

declare(strict_types=1);

namespace Modules\Forecasting\Domain\ValueObjects;

/**
 * The tenant's recurring statutory amounts, estimated from its payroll runs and VAT settlements and used to size the
 * tax, social security and payroll events of the calendar.
 */
final readonly class StatutoryProfile
{
    public function __construct(
        /** One month of salaries, the amount of a 13th or 14th month payment. */
        public float $monthlyPayroll = 0.0,
        /** IT: withholding taxes, INPS contributions and IRPEF of the payroll (F24 of the 16th). */
        public float $monthlyWithholdingAndContributions = 0.0,
        /** VAT due for a quarter; also the Q4 annual balance. */
        public float $quarterlyVat = 0.0,
        /** IT: the December VAT advance (acconto IVA). */
        public float $vatAdvance = 0.0,
        /** IT: the yearly income tax (IRES/IRAP/IRPEF) the two advances are taken from (40% in June, 60% in November). */
        public float $annualIncomeTaxAdvance = 0.0,
        /** CH: AVS/AI/IPG and LPP/BVG installment of a quarter. */
        public float $quarterlySocialSecurity = 0.0,
        /** CH: the year-end reconciliation of the social security installments. */
        public float $socialSecurityReconciliation = 0.0,
        public bool $paysThirteenthSalary = true,
        /** IT: whether the collective agreement (CCNL) of the tenant pays a 14th month. */
        public bool $paysFourteenthSalary = false,
        /** CH: month (11 or 12) the 13th monthly salary is paid. */
        public int $swissThirteenthSalaryMonth = 11,
    ) {}

    /**
     * @param  array<string, mixed>  $values  keys as the properties in snake_case
     */
    public static function fromArray(array $values): self
    {
        return new self(
            (float) ($values['monthly_payroll'] ?? 0.0),
            (float) ($values['monthly_withholding_and_contributions'] ?? 0.0),
            (float) ($values['quarterly_vat'] ?? 0.0),
            (float) ($values['vat_advance'] ?? 0.0),
            (float) ($values['annual_income_tax_advance'] ?? 0.0),
            (float) ($values['quarterly_social_security'] ?? 0.0),
            (float) ($values['social_security_reconciliation'] ?? 0.0),
            (bool) ($values['pays_thirteenth_salary'] ?? true),
            (bool) ($values['pays_fourteenth_salary'] ?? false),
            (int) ($values['swiss_thirteenth_salary_month'] ?? 11) === 12 ? 12 : 11,
        );
    }
}
