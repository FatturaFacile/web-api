<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\HumanResources\Http\Requests\CloseTimePeriodRequest;
use Modules\HumanResources\Http\Requests\HrPeriodRequest;
use Modules\HumanResources\Http\Requests\ReasonRequest;
use Modules\HumanResources\Http\Requests\SaveOvertimeRuleRequest;
use Modules\HumanResources\Models\OvertimeRule;
use Modules\HumanResources\Models\TimeClockEvent;
use Modules\HumanResources\Models\TimeNightCompensationBalance;
use Modules\HumanResources\Models\TimePeriodClosing;
use Modules\HumanResources\Services\TimeTracking\NightWorkCompensationService;
use Modules\HumanResources\Services\TimeTracking\OvertimeCalculator;
use Modules\HumanResources\Services\TimeTracking\TimePeriodClosingService;
use Modules\HumanResources\Services\TimeTracking\TimeToPayrollTransferService;
use OpenApi\Attributes as OA;

/**
 * Overtime rules, the overtime of an employee and the monthly closing of the working time records.
 */
final class WorkingTimeRulesController extends Controller
{
    public function __construct(
        private readonly OvertimeCalculator $overtime,
        private readonly TimePeriodClosingService $closings,
        private readonly TenantContext $tenantContext,
        private readonly TimeToPayrollTransferService $payrollTransfer,
        private readonly NightWorkCompensationService $nightCompensation,
    ) {}

    /**
     * List the overtime rules.
     */
    #[OA\Get(
        path: '/api/v1/hr/overtime-rules',
        operationId: 'listHrOvertimeRules',
        description: 'Overtime rules of the company (daily or weekly threshold, premium, wage type). None is shipped: thresholds and premiums are company data.',
        summary: 'List overtime rules',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Time & Attendance'],
        responses: [
            new OA\Response(response: 200, description: 'Rules', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
        ]
    )]
    public function rules(): JsonResponse
    {
        return response()->json(['data' => OvertimeRule::query()->orderBy('basis')->orderBy('threshold_minutes')->get()]);
    }

    /**
     * Create an overtime rule.
     */
    #[OA\Post(
        path: '/api/v1/hr/overtime-rules',
        operationId: 'storeHrOvertimeRule',
        description: 'Create an overtime rule. Rules of the same basis form tiers by threshold.',
        summary: 'Create overtime rule',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HrOvertimeRuleRequest')),
        tags: ['HR Time & Attendance'],
        responses: [
            new OA\Response(response: 201, description: 'Rule created', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function storeRule(SaveOvertimeRuleRequest $request): JsonResponse
    {
        return response()->json(['data' => OvertimeRule::query()->create($request->validated())], 201);
    }

    /**
     * Change an overtime rule.
     */
    #[OA\Put(
        path: '/api/v1/hr/overtime-rules/{id}',
        operationId: 'updateHrOvertimeRule',
        description: 'Change an overtime rule.',
        summary: 'Update overtime rule',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HrOvertimeRuleRequest')),
        tags: ['HR Time & Attendance'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/HrRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Rule updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function updateRule(SaveOvertimeRuleRequest $request, string $id): JsonResponse
    {
        $rule = OvertimeRule::query()->findOrFail($id);
        $rule->update($request->validated());

        return response()->json(['data' => $rule]);
    }

    /**
     * Delete an overtime rule.
     */
    #[OA\Delete(
        path: '/api/v1/hr/overtime-rules/{id}',
        operationId: 'deleteHrOvertimeRule',
        description: 'Delete an overtime rule (soft delete).',
        summary: 'Delete overtime rule',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Time & Attendance'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/HrRecordId')],
        responses: [
            new OA\Response(response: 204, description: 'Rule deleted'),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
        ]
    )]
    public function destroyRule(string $id): Response
    {
        OvertimeRule::query()->findOrFail($id)->delete();

        return response()->noContent();
    }

    /**
     * The overtime of an employee in a period.
     */
    #[OA\Get(
        path: '/api/v1/hr/time/overtime',
        operationId: 'hrTimeOvertime',
        description: 'Overtime of an employee by day, ISO week and rule, from the recorded working time and the overtime rules of the company. Employees see their own; HR managers anybody (user_id).',
        summary: 'Overtime of an employee',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Time & Attendance'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/HrPeriodFrom'),
            new OA\Parameter(ref: '#/components/parameters/HrPeriodTo'),
            new OA\Parameter(ref: '#/components/parameters/HrUserFilter'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Overtime', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function overtime(HrPeriodRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $employeeId = (string) ($request->validated('user_id') ?? $actor->id);
        $this->authorize('viewRecords', [TimeClockEvent::class, $employeeId]);

        $employee = User::query()->find($employeeId);
        abort_unless($employee !== null && $employee->belongsToTenant((string) $this->tenantContext->getTenantId()), 404);

        return response()->json(['data' => $this->overtime->calculate($employee, (string) $request->validated('from'), (string) $request->validated('to'))]);
    }

    /**
     * List the monthly closings.
     */
    #[OA\Get(
        path: '/api/v1/hr/time-closings',
        operationId: 'listHrTimeClosings',
        description: 'Monthly closings of the working time records with their status, totals at closing and reopenings.',
        summary: 'List time closings',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Time & Attendance'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/HrYearFilter')],
        responses: [
            new OA\Response(response: 200, description: 'Closings', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
        ]
    )]
    public function closings(Request $request): JsonResponse
    {
        return response()->json(['data' => TimePeriodClosing::query()
            ->when($request->integer('year') > 0, static fn ($query) => $query->where('year', $request->integer('year')))
            ->orderByDesc('year')->orderByDesc('month')->get()]);
    }

    /**
     * Close a month.
     */
    #[OA\Post(
        path: '/api/v1/hr/time-closings',
        operationId: 'storeHrTimeClosing',
        description: 'Close the working time records of a month: clock events, daily records and legacy time entries of the month can no longer be created, corrected or deleted until the month is reopened.',
        summary: 'Close a month',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HrTimeClosingRequest')),
        tags: ['HR Time & Attendance'],
        responses: [
            new OA\Response(response: 201, description: 'Month closed', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrConflict', response: 409),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function close(CloseTimePeriodRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json([
            'data' => $this->closings->close(
                $actor,
                (int) $request->validated('year'),
                (int) $request->validated('month'),
                (bool) $request->validated('transfer_to_payroll', false),
            ),
        ], 201);
    }

    /**
     * Transfer a closed (or approved) attendance period to payroll variable inputs.
     */
    #[OA\Post(
        path: '/api/v1/hr/time-closings/{year}/{month}/transfer-to-payroll',
        operationId: 'transferHrTimeToPayroll',
        description: 'Evaluates the working time of every employee linked to a user for the month and creates or updates the payroll variable inputs: hourly hours for hourly-paid employees (1001), overtime at 125% (1010), the 25% night supplement for occasional night workers (1020) and the 50% Sunday and public holiday supplement (1030). Regular night workers (25 or more night shifts in the year, ArG Art. 17b) get 10% compensatory rest time credited to the night work balance instead of the cash supplement. Runs in one transaction and is idempotent: running it again overwrites the inputs of the same employee, month and wage type. The month does not have to be closed first. Responds 200 (not 201) because inputs are upserted.',
        summary: 'Transfer working time to payroll inputs',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Time & Attendance'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/HrPayrollTransferYear'),
            new OA\Parameter(ref: '#/components/parameters/HrPayrollTransferMonth'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Transfer result', content: new OA\JsonContent(ref: '#/components/schemas/HrTimeToPayrollResponse')),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
        ]
    )]
    public function transferToPayroll(Request $request, int $year, int $month): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json([
            'data' => $this->payrollTransfer->transfer($year, $month, $actor),
        ]);
    }

    /**
     * List or evaluate ArG Art. 17b night work compensation balances for a calendar year.
     */
    #[OA\Get(
        path: '/api/v1/hr/time/night-work-balances',
        operationId: 'listHrNightWorkBalances',
        description: 'Night work compensation banks of the company for a calendar year (ArG Art. 17b): night shifts so far, whether the employee is a regular night worker (25 or more shifts, entitled to 10% compensatory rest time) and the rest time accrued, taken and remaining. With user_id the balance of that employee is first re-evaluated from the recorded working time, so the call writes the balance row; without it the stored balances are listed as they are.',
        summary: 'Night work compensation balances',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Time & Attendance'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/HrNightWorkYear'),
            new OA\Parameter(ref: '#/components/parameters/HrNightWorkUserFilter'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Balances', content: new OA\JsonContent(ref: '#/components/schemas/HrNightWorkBalanceListResponse')),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
        ]
    )]
    public function nightWorkBalances(Request $request): JsonResponse
    {
        $year = $request->integer('year') ?: (int) now()->year;
        $userId = $request->string('user_id')->toString();

        if ($userId !== '') {
            $user = User::query()->find($userId);
            abort_unless($user !== null && $user->belongsToTenant((string) $this->tenantContext->getTenantId()), 404);
            $this->nightCompensation->evaluateYearToDate($user, $year);
        }

        return response()->json([
            'data' => TimeNightCompensationBalance::query()
                ->where('year', $year)
                ->when($userId !== '', static fn ($q) => $q->where('user_id', $userId))
                ->orderByDesc('ytd_night_shifts')
                ->get(),
        ]);
    }

    /**
     * Reopen a closed month.
     */
    #[OA\Post(
        path: '/api/v1/hr/time-closings/{id}/reopen',
        operationId: 'reopenHrTimeClosing',
        description: 'Reopen a closed month. The reason is mandatory and stays in the time audit history.',
        summary: 'Reopen a month',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HrReasonRequest')),
        tags: ['HR Time & Attendance'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/HrRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Month reopened', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function reopen(ReasonRequest $request, string $id): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $this->closings->reopen($actor, TimePeriodClosing::query()->findOrFail($id), (string) $request->validated('reason'))]);
    }
}
