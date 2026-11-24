<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Domain\ValueObjects;

/**
 * Evaluation of Italian periodo di comporto, Swiss Art. 329a/329b CO Ferienkürzung, and vacation expiration alerts.
 */
final readonly class ComportoOrCurtailmentEvaluation
{
    /**
     * @param  array{
     *     applicable: bool,
     *     window_months: int,
     *     max_days: int,
     *     sick_days_in_window: float,
     *     remaining_days: float,
     *     utilization_percent: float,
     *     status: string
     * }  $italianComporto
     * @param  array{
     *     applicable: bool,
     *     art329a_base_entitlement_days: float,
     *     grace_months: int,
     *     qualifying_absence_days: float,
     *     full_absence_months: int,
     *     reducible_months: int,
     *     reduction_fraction: string,
     *     reduction_days: float,
     *     net_entitlement_days: float,
     *     applied_to_ledger: bool
     * }  $swissCurtailment
     * @param  list<array{
     *     leave_type: string,
     *     fiscal_year: int,
     *     deadline_date: string,
     *     expiring_amount: float,
     *     alert_level: string
     * }>  $vacationDeadlineAlerts
     */
    public function __construct(
        public string $employeeId,
        public string $employeeName,
        public int $fiscalYear,
        public string $country,
        public array $italianComporto,
        public array $swissCurtailment,
        public array $vacationDeadlineAlerts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'employee_id' => $this->employeeId,
            'employee_name' => $this->employeeName,
            'fiscal_year' => $this->fiscalYear,
            'country' => $this->country,
            'italian_comporto' => $this->italianComporto,
            'swiss_curtailment' => $this->swissCurtailment,
            'vacation_deadline_alerts' => $this->vacationDeadlineAlerts,
        ];
    }
}
