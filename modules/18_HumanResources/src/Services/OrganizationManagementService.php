<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Modules\HumanResources\Contracts\OrganizationManagementServiceInterface;
use Modules\HumanResources\Events\AccrualReadyEvent;
use Modules\HumanResources\Events\PayrollForecastEvent;
use Modules\HumanResources\Models\Department;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\Position;

final readonly class OrganizationManagementService implements OrganizationManagementServiceInterface
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger
    ) {}

    public function buildOrgChart(string $tenantId): array
    {
        $departments = Department::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->with(['teams', 'positions', 'employees'])
            ->get();

        $tree = [];
        foreach ($departments as $dept) {
            $tree[] = [
                'department_id' => (string) $dept->id,
                'code' => (string) $dept->code,
                'name' => (string) $dept->name,
                'parent_id' => $dept->parent_id ? (string) $dept->parent_id : null,
                'manager_employee_id' => $dept->manager_employee_id ? (string) $dept->manager_employee_id : null,
                'headcount' => $dept->employees->count(),
                'teams_count' => $dept->teams->count(),
                'positions_count' => $dept->positions->count(),
            ];
        }

        return $tree;
    }

    public function assignSuccessor(
        Position $position,
        string $successorEmployeeId,
        string $readinessLevel = 'ready_1_2_years',
        ?string $actorUserId = null
    ): Position {
        return DB::transaction(function () use ($position, $successorEmployeeId, $readinessLevel) {
            $locked = Position::where('id', $position->id)->lockForUpdate()->firstOrFail();
            $before = $locked->toArray();

            $locked->update([
                'successor_employee_id' => $successorEmployeeId,
                'readiness_level' => $readinessLevel,
            ]);

            $this->auditLedger->record(
                action: 'HR_SUCCESSOR_ASSIGNED',
                model: $locked,
                payloadBefore: $before,
                payloadAfter: $locked->fresh()->toArray(),
                moduleCode: 'MODULE_18_HR'
            );

            return $locked->fresh();
        });
    }

    public function generatePeriodCostAndPayrollForecast(string $tenantId, string $periodCode): array
    {
        $activeEmployees = Employee::where('tenant_id', $tenantId)
            ->whereIn('employment_status', ['onboarding', 'probation', 'active', 'promoted', 'transferred', 'rehired'])
            ->get();

        $headcount = $activeEmployees->count();
        $annualGrossSum = (float) $activeEmployees->sum('base_annual_salary');
        $monthlyPayrollOutflow = round(($annualGrossSum / 12.0) * 1.35, 2);
        $monthlyAccrualAmount = round(($annualGrossSum / 12.0) * 0.15, 2); // 13th month + TFR/severance accrual

        if ($monthlyPayrollOutflow > 0.0) {
            $payrollForecast = new PayrollForecastEvent(
                periodCode: $periodCode,
                estimatedPayrollOutflow: $monthlyPayrollOutflow,
                currency: 'EUR',
                expectedPaymentDate: date('Y-m-27'),
                activeHeadcount: $headcount,
                tenantId: $tenantId,
                companyId: $tenantId
            );
            $this->dispatcher->publish($payrollForecast);
            event($payrollForecast);

            $accrualReady = new AccrualReadyEvent(
                accrualType: 'payroll_13th_and_tfr_provision',
                periodCode: $periodCode,
                accrualAmount: $monthlyAccrualAmount,
                glDebitAccountCode: '6150',
                glCreditAccountCode: '2250',
                tenantId: $tenantId,
                companyId: $tenantId
            );
            $this->dispatcher->publish($accrualReady);
            event($accrualReady);
        }

        return [
            'period_code' => $periodCode,
            'active_headcount' => $headcount,
            'estimated_payroll_outflow' => $monthlyPayrollOutflow,
            'accrual_amount' => $monthlyAccrualAmount,
        ];
    }
}
