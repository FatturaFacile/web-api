<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\HumanResources\Models\Employee;
use Modules\LeaveManagement\Application\Commands\CalculateMonthlyLeaveAccrualsCommand;
use Modules\LeaveManagement\Application\Commands\CalculateMonthlyLeaveAccrualsHandler;
use Modules\LeaveManagement\Application\Queries\EvaluateLongSickLeaveCurteilungOrComportoHandler;
use Modules\LeaveManagement\Application\Queries\EvaluateLongSickLeaveCurteilungOrComportoQuery;
use Modules\LeaveManagement\Domain\Contracts\PayrollAbsenceFeedPortInterface;
use Modules\LeaveManagement\Http\Controllers\Concerns\ResolvesLeaveContext;
use Modules\LeaveManagement\Http\Requests\CalculateMonthlyAccrualsRequest;
use Modules\LeaveManagement\Http\Resources\EmployeeLeaveAccrualLedgerResource;
use Modules\LeaveManagement\Models\EmployeeLeaveAccrualLedger;
use OpenApi\Attributes as OA;

/**
 * Employee leave accrual ledgers (Ferie, ROL, Ex-Festività, CH Ferien), monthly pro-rata accrual execution,
 * comporto / Art. 329b CO Ferienkürzung evaluation, and payroll absence integration feed.
 */
final class LeaveAccrualLedgerController extends Controller
{
    use ApiResponse;
    use ResolvesLeaveContext;

    public function __construct(
        private readonly CalculateMonthlyLeaveAccrualsHandler $calculateHandler,
        private readonly EvaluateLongSickLeaveCurteilungOrComportoHandler $comportoHandler,
        private readonly PayrollAbsenceFeedPortInterface $payrollFeed,
    ) {}

    #[OA\Get(
        path: '/api/v1/leave-management/ledgers',
        operationId: 'leaveMgmtListLedgers',
        summary: 'List employee leave accrual ledgers (Ferie, ROL, Ex-Festività, CH Ferien)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Leave Management'],
        responses: [new OA\Response(response: 200, description: 'Accrual ledgers')]
    )]
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $user = $request->user();
        $canManage = $user !== null && Gate::allows('manageConfiguration', EmployeeLeaveAccrualLedger::class);

        $employeeId = $request->query('employee_id');
        $fiscalYear = $request->query('fiscal_year');
        $country = $request->query('country');
        $leaveType = $request->query('leave_type');

        $query = EmployeeLeaveAccrualLedger::query()
            ->where('tenant_id', $tenantId)
            ->with('employee:id,full_name,employee_number')
            ->when(is_string($fiscalYear) && $fiscalYear !== '', static fn ($q) => $q->where('fiscal_year', (int) $fiscalYear))
            ->when(is_string($country) && $country !== '', static fn ($q) => $q->where('country', strtoupper($country)))
            ->when(is_string($leaveType) && $leaveType !== '', static fn ($q) => $q->where('leave_type', $leaveType));

        if ($canManage) {
            if (is_string($employeeId) && $employeeId !== '') {
                $query->where('employee_id', $employeeId);
            }
        } else {
            $ownEmployeeIds = Employee::query()
                ->where('tenant_id', $tenantId)
                ->where('user_id', $user?->id)
                ->pluck('id')
                ->all();
            $query->whereIn('employee_id', $ownEmployeeIds === [] ? ['00000000-0000-0000-0000-000000000000'] : $ownEmployeeIds);
        }

        $ledgers = $query->orderByDesc('fiscal_year')->orderBy('leave_type')->get();

        return $this->successResponse(EmployeeLeaveAccrualLedgerResource::collection($ledgers));
    }

    #[OA\Post(
        path: '/api/v1/leave-management/ledgers/calculate-monthly',
        operationId: 'leaveMgmtCalculateMonthlyAccruals',
        summary: 'Execute monthly pro-rata accrual calculation (FTE, hire/termination dates, Art. 329a CO)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Leave Management'],
        responses: [new OA\Response(response: 200, description: 'Monthly accruals calculated')]
    )]
    public function calculateMonthly(CalculateMonthlyAccrualsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $ledgers = $this->calculateHandler->handle(new CalculateMonthlyLeaveAccrualsCommand(
            tenantId: $this->tenantId(),
            fiscalYear: (int) $validated['fiscal_year'],
            month: (int) $validated['month'],
            employeeId: $validated['employee_id'] ?? null,
            country: $validated['country'] ?? null,
        ));

        return $this->successResponse([
            'processed_ledgers' => count($ledgers),
            'ledgers' => EmployeeLeaveAccrualLedgerResource::collection(collect($ledgers)),
        ], __('leave_management.messages.accruals_calculated'));
    }

    #[OA\Get(
        path: '/api/v1/leave-management/comporto-curtailment',
        operationId: 'leaveMgmtEvaluateComportoOrCurtailment',
        summary: 'Evaluate Italian periodo di comporto, Swiss Art. 329b CO Ferienkürzung, and vacation deadlines',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Leave Management'],
        responses: [new OA\Response(response: 200, description: 'Comporto and Art. 329b CO evaluation')]
    )]
    public function evaluateComportoOrCurtailment(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $employeeId = (string) $request->query('employee_id', '');
        if ($employeeId === '') {
            return $this->problemDetailsResponse('Validation Error', __('leave_management.errors.employee_id_required'), 422);
        }

        $canManage = Gate::allows('manageConfiguration', EmployeeLeaveAccrualLedger::class);
        if (! $canManage) {
            $ownsRecord = Employee::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $employeeId)
                ->where('user_id', $request->user()?->id)
                ->exists();
            if (! $ownsRecord) {
                abort(403);
            }
        }

        $fiscalYear = (int) $request->query('fiscal_year', (string) date('Y'));
        $country = $request->query('country');
        $apply = filter_var($request->query('apply_curtailment', false), FILTER_VALIDATE_BOOLEAN);

        if ($apply && ! $canManage) {
            abort(403);
        }

        $evaluation = $this->comportoHandler->handle(new EvaluateLongSickLeaveCurteilungOrComportoQuery(
            tenantId: $tenantId,
            employeeId: $employeeId,
            fiscalYear: $fiscalYear,
            country: is_string($country) && $country !== '' ? $country : null,
            applyCurtailment: $apply,
        ));

        return $this->successResponse($evaluation->toArray());
    }

    #[OA\Get(
        path: '/api/v1/leave-management/integrations/payroll-summary',
        operationId: 'leaveMgmtPayrollAbsenceSummary',
        summary: 'Monthly absence & indemnity integration feed for CH Payroll (Mod 076) and IT Payroll (Mod 077)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Leave Management'],
        responses: [new OA\Response(response: 200, description: 'Payroll absence summary')]
    )]
    public function payrollSummary(Request $request): JsonResponse
    {
        Gate::authorize('manageConfiguration', EmployeeLeaveAccrualLedger::class);

        $year = (int) $request->query('year', (string) date('Y'));
        $month = (int) $request->query('month', (string) date('n'));
        $country = $request->query('country');

        $summary = $this->payrollFeed->getMonthlyPayrollSummary(
            tenantId: $this->tenantId(),
            year: $year,
            month: $month,
            country: is_string($country) && $country !== '' ? $country : null,
        );

        return $this->successResponse($summary);
    }
}
