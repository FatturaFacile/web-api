<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Domain\Contracts;

/**
 * Integration contract for CH Payroll (Mod 076) and IT Payroll (Mod 077).
 */
interface PayrollAbsenceFeedPortInterface
{
    /**
     * Aggregate monthly leave, carenza/INPS PUC, SUVA/INAIL, IJM/KTG, IPG/EO, and Art. 329b CO curtailment data.
     *
     * @return list<array<string, mixed>>
     */
    public function getMonthlyPayrollSummary(string $tenantId, int $year, int $month, ?string $country = null): array;
}
