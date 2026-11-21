<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\Contracts;

use Modules\ShiftPlanning\Domain\Enums\Jurisdiction;
use Modules\ShiftPlanning\Domain\ValueObjects\ComplianceContext;
use Modules\ShiftPlanning\Domain\ValueObjects\ComplianceIssue;
use Modules\ShiftPlanning\Domain\ValueObjects\PlannedShift;
use Modules\ShiftPlanning\Domain\ValueObjects\SurchargeBreakdown;

/**
 * The statutory labour rules of one jurisdiction (and, in a subclass, of a collective agreement). The strategy is pure: it
 * receives the other shifts of the employee and reads nothing from the database.
 */
interface LaborComplianceStrategyInterface
{
    public function jurisdiction(): Jurisdiction;

    /**
     * @param  list<PlannedShift>  $employeeShifts  the other shifts of the employee around the candidate (never the candidate)
     * @return list<ComplianceIssue>
     */
    public function validate(PlannedShift $candidate, array $employeeShifts, ComplianceContext $context): array;

    public function calculateSurcharges(PlannedShift $shift, ComplianceContext $context): SurchargeBreakdown;
}
