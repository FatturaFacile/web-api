<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Modules\HumanResources\Contracts\LeaveAndTimeServiceInterface;
use Modules\HumanResources\Http\Requests\HrPeriodRequest;
use Modules\HumanResources\Http\Requests\ReasonRequest;
use Modules\HumanResources\Http\Requests\SaveLeaveAccrualPolicyRequest;
use Modules\HumanResources\Http\Requests\SaveLeaveTypeRequest;
use Modules\HumanResources\Http\Resources\LeaveRequestResource;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\LeaveAccrualPolicy;
use Modules\HumanResources\Models\LeaveRequest;
use Modules\HumanResources\Models\LeaveType;
use OpenApi\Attributes as OA;

/**
 * Leave configuration and planning for HR roles: absence types, accrual policies, cancellation and the leave calendar.
 */
final class LeaveManagementController extends Controller
{
    public function __construct(private readonly LeaveAndTimeServiceInterface $leave) {}

    /**
     * Create an absence type.
     */
    #[OA\Post(
        path: '/api/v1/hr/leave-types',
        operationId: 'storeHrLeaveType',
        description: 'Create an absence (leave) type of the company. The yearly entitlement is company data; leave types without approval are approved at submission.',
        summary: 'Create leave type',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HrLeaveTypeRequest')),
        tags: ['HR Leave'],
        responses: [
            new OA\Response(response: 201, description: 'Leave type created', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function storeType(SaveLeaveTypeRequest $request): JsonResponse
    {
        return response()->json(['data' => LeaveType::query()->create($request->validated())], 201);
    }

    /**
     * Change an absence type.
     */
    #[OA\Put(
        path: '/api/v1/hr/leave-types/{id}',
        operationId: 'updateHrLeaveType',
        description: 'Change an absence type of the company. Existing balances keep their hours.',
        summary: 'Update leave type',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HrLeaveTypeRequest')),
        tags: ['HR Leave'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/HrRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Leave type updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function updateType(SaveLeaveTypeRequest $request, string $id): JsonResponse
    {
        $type = LeaveType::query()->findOrFail($id);
        $type->update($request->validated());

        return response()->json(['data' => $type]);
    }

    /**
     * List the accrual policies.
     */
    #[OA\Get(
        path: '/api/v1/hr/leave-accrual-policies',
        operationId: 'listHrLeaveAccrualPolicies',
        description: 'Accrual policies of the company: yearly entitlement, monthly or yearly accrual, FTE proration, balance cap and carry-over limit per leave type (and optionally per employment type).',
        summary: 'List leave accrual policies',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Leave'],
        responses: [
            new OA\Response(response: 200, description: 'Policies', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
        ]
    )]
    public function policies(): AnonymousResourceCollection
    {
        return JsonResource::collection(LeaveAccrualPolicy::query()->orderBy('code')->gridPaginate(RequestedPerPage::resolve(200)));
    }

    /**
     * Create an accrual policy.
     */
    #[OA\Post(
        path: '/api/v1/hr/leave-accrual-policies',
        operationId: 'storeHrLeaveAccrualPolicy',
        description: 'Create an accrual policy. The monthly accrual (scheduled on the first of the month, or POST /leave-balances/accrue) adds a twelfth of the entitlement once per month; a yearly policy grants it once per year.',
        summary: 'Create leave accrual policy',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HrLeaveAccrualPolicyRequest')),
        tags: ['HR Leave'],
        responses: [
            new OA\Response(response: 201, description: 'Policy created', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function storePolicy(SaveLeaveAccrualPolicyRequest $request): JsonResponse
    {
        return response()->json(['data' => LeaveAccrualPolicy::query()->create($request->validated())], 201);
    }

    /**
     * Change an accrual policy.
     */
    #[OA\Put(
        path: '/api/v1/hr/leave-accrual-policies/{id}',
        operationId: 'updateHrLeaveAccrualPolicy',
        description: 'Change an accrual policy; months already accrued are not recalculated.',
        summary: 'Update leave accrual policy',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HrLeaveAccrualPolicyRequest')),
        tags: ['HR Leave'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/HrRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Policy updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function updatePolicy(SaveLeaveAccrualPolicyRequest $request, string $id): JsonResponse
    {
        $policy = LeaveAccrualPolicy::query()->findOrFail($id);
        $policy->update($request->validated());

        return response()->json(['data' => $policy]);
    }

    /**
     * Delete an accrual policy.
     */
    #[OA\Delete(
        path: '/api/v1/hr/leave-accrual-policies/{id}',
        operationId: 'deleteHrLeaveAccrualPolicy',
        description: 'Delete an accrual policy (soft delete); the accrual journal keeps its reference.',
        summary: 'Delete leave accrual policy',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Leave'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/HrRecordId')],
        responses: [
            new OA\Response(response: 204, description: 'Policy deleted'),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
        ]
    )]
    public function destroyPolicy(string $id): Response
    {
        LeaveAccrualPolicy::query()->findOrFail($id)->delete();

        return response()->noContent();
    }

    /**
     * Cancel a leave request.
     */
    #[OA\Post(
        path: '/api/v1/hr/leave-requests/{id}/cancel',
        operationId: 'cancelHrLeaveRequest',
        description: 'Cancel a pending request (its approval workflow is cancelled) or an approved request that has not started yet. The hours return to the balance; the reason stays in the request.',
        summary: 'Cancel leave request',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HrReasonRequest')),
        tags: ['HR Leave'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/HrRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Request cancelled', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/HrConflict', response: 409),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function cancel(ReasonRequest $request, string $id): JsonResponse
    {
        $leaveRequest = LeaveRequest::query()->findOrFail($id);
        $cancelled = $this->leave->cancelLeaveRequest($leaveRequest, (string) $request->validated('reason'), (string) $request->user()?->getAuthIdentifier());

        return response()->json(['data' => new LeaveRequestResource($cancelled)]);
    }

    /**
     * The leave calendar of a period.
     */
    #[OA\Get(
        path: '/api/v1/hr/leave-calendar',
        operationId: 'hrLeaveCalendar',
        description: 'Pending and approved leave overlapping the period, by employee, optionally for one department. Reasons and medical references are not included.',
        summary: 'Leave calendar',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Leave'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/HrPeriodFrom'),
            new OA\Parameter(ref: '#/components/parameters/HrPeriodTo'),
            new OA\Parameter(name: 'department_id', description: 'Only this department', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Calendar entries', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function calendar(HrPeriodRequest $request): JsonResponse
    {
        $from = (string) $request->validated('from');
        $to = (string) $request->validated('to');
        $departmentId = $request->validated('department_id');

        $requests = LeaveRequest::query()
            ->whereIn('status', ['pending_approval', 'approved'])
            ->whereDate('start_date', '<=', $to)
            ->whereDate('end_date', '>=', $from)
            ->when($departmentId !== null, static fn ($query) => $query->whereIn('employee_id', Employee::query()->where('department_id', $departmentId)->select('id')))
            ->with(['employee:id,full_name,department_id', 'leaveType:id,code,name,category'])
            ->orderBy('start_date')
            ->get();

        return response()->json(['data' => $requests->map(static fn (LeaveRequest $leave): array => [
            'id' => $leave->id,
            'employee_id' => $leave->employee_id,
            'employee_name' => $leave->employee?->full_name,
            'department_id' => $leave->employee?->department_id,
            'leave_type_code' => $leave->leaveType?->code,
            'leave_type_name' => $leave->leaveType?->name,
            'start_date' => $leave->start_date->format('Y-m-d'),
            'end_date' => $leave->end_date->format('Y-m-d'),
            'total_hours' => (string) $leave->total_hours,
            'status' => $leave->status,
        ])->values()]);
    }
}
