<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\HumanResources\Models\Employee;
use Modules\LeaveManagement\Application\Commands\DecideLeaveRequestCommand;
use Modules\LeaveManagement\Application\Commands\DecideLeaveRequestHandler;
use Modules\LeaveManagement\Application\Commands\RegisterSickLeaveWithInpsProtocolCommand;
use Modules\LeaveManagement\Application\Commands\RegisterSickLeaveWithInpsProtocolHandler;
use Modules\LeaveManagement\Application\Commands\SubmitLeaveRequestCommand;
use Modules\LeaveManagement\Application\Commands\SubmitLeaveRequestHandler;
use Modules\LeaveManagement\Http\Controllers\Concerns\ResolvesLeaveContext;
use Modules\LeaveManagement\Http\Requests\DecideLeaveRequestFormRequest;
use Modules\LeaveManagement\Http\Requests\RegisterSickLeaveInpsRequest;
use Modules\LeaveManagement\Http\Requests\SubmitLeaveRequestFormRequest;
use Modules\LeaveManagement\Http\Resources\LeaveRequestResource;
use Modules\LeaveManagement\Models\LeaveRequest;
use OpenApi\Attributes as OA;

/**
 * Leave requests, INPS PUC sick leave registration, SUVA/INAIL accident registration, and approval decisions.
 */
final class LeaveRequestController extends Controller
{
    use ApiResponse;
    use ResolvesLeaveContext;

    public function __construct(
        private readonly SubmitLeaveRequestHandler $submitHandler,
        private readonly RegisterSickLeaveWithInpsProtocolHandler $sickInpsHandler,
        private readonly DecideLeaveRequestHandler $decideHandler,
    ) {}

    #[OA\Get(
        path: '/api/v1/leave-management/requests',
        operationId: 'leaveMgmtListRequests',
        summary: 'List leave, sick, accident, and permit requests',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Leave Management'],
        responses: [new OA\Response(response: 200, description: 'Leave requests')]
    )]
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $user = $request->user();
        $canManage = $user !== null && Gate::allows('manageConfiguration', LeaveRequest::class);

        $country = $request->query('country');
        $leaveType = $request->query('leave_type');
        $status = $request->query('status');
        $employeeId = $request->query('employee_id');
        $departmentId = $request->query('department_id');
        $from = $request->query('from');
        $to = $request->query('to');

        $query = LeaveRequest::query()
            ->where('tenant_id', $tenantId)
            ->with(['employee:id,full_name,employee_number', 'department:id,name'])
            ->when(is_string($country) && $country !== '', static fn ($q) => $q->where('country', strtoupper($country)))
            ->when(is_string($leaveType) && $leaveType !== '', static fn ($q) => $q->where('leave_type', $leaveType))
            ->when(is_string($status) && $status !== '', static fn ($q) => $q->where('status', $status))
            ->when(is_string($departmentId) && $departmentId !== '', static fn ($q) => $q->where('department_id', $departmentId))
            ->when(is_string($from) && $from !== '', static fn ($q) => $q->whereDate('end_date', '>=', $from))
            ->when(is_string($to) && $to !== '', static fn ($q) => $q->whereDate('start_date', '<=', $to));

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

        $items = $query->orderByDesc('start_date')->orderByDesc('created_at')->get();

        return $this->successResponse(LeaveRequestResource::collection($items));
    }

    #[OA\Get(
        path: '/api/v1/leave-management/requests/{id}',
        operationId: 'leaveMgmtShowRequest',
        summary: 'Get a single leave request',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Leave Management'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Leave request detail')]
    )]
    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = $this->tenantId();
        $leaveRequest = LeaveRequest::query()
            ->where('tenant_id', $tenantId)
            ->with(['employee:id,full_name,employee_number,user_id', 'department:id,name'])
            ->findOrFail($id);

        if (! Gate::allows('manageConfiguration', LeaveRequest::class) && $leaveRequest->employee?->user_id !== $request->user()?->id) {
            abort(403);
        }

        return $this->successResponse(new LeaveRequestResource($leaveRequest));
    }

    #[OA\Post(
        path: '/api/v1/leave-management/requests',
        operationId: 'leaveMgmtSubmitRequest',
        summary: 'Submit a leave or permit request (checks balance, overlaps, Legge 104 limit, and department staffing)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Leave Management'],
        responses: [new OA\Response(response: 201, description: 'Leave request submitted')]
    )]
    public function store(SubmitLeaveRequestFormRequest $request): JsonResponse
    {
        $leaveRequest = $this->submitHandler->handle(new SubmitLeaveRequestCommand(
            tenantId: $this->tenantId(),
            payload: $request->validated(),
            actorUserId: $this->userId($request),
        ));

        return $this->successResponse(new LeaveRequestResource($leaveRequest), __('leave_management.messages.request_submitted'), 201);
    }

    #[OA\Post(
        path: '/api/v1/leave-management/requests/sick-inps',
        operationId: 'leaveMgmtRegisterSickInps',
        summary: 'Register sick leave or accident with INPS 9-char PUC, SUVA/INAIL claim, IJM/KTG Wartefrist, or IPG/EO',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Leave Management'],
        responses: [new OA\Response(response: 201, description: 'Sick/accident absence registered')]
    )]
    public function registerSickInps(RegisterSickLeaveInpsRequest $request): JsonResponse
    {
        $leaveRequest = $this->sickInpsHandler->handle(new RegisterSickLeaveWithInpsProtocolCommand(
            tenantId: $this->tenantId(),
            payload: $request->validated(),
            actorUserId: $this->userId($request),
        ));

        return $this->successResponse(new LeaveRequestResource($leaveRequest), __('leave_management.messages.sick_registered'), 201);
    }

    #[OA\Post(
        path: '/api/v1/leave-management/requests/{id}/approve',
        operationId: 'leaveMgmtApproveRequest',
        summary: 'Approve a pending leave request',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Leave Management'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Leave request approved')]
    )]
    public function approve(DecideLeaveRequestFormRequest $request, string $id): JsonResponse
    {
        $leaveRequest = LeaveRequest::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        Gate::authorize('decide', $leaveRequest);

        $updated = $this->decideHandler->handle(new DecideLeaveRequestCommand(
            tenantId: $this->tenantId(),
            leaveRequestId: $id,
            decision: 'approve',
            reason: null,
            actorUserId: $this->userId($request),
        ));

        return $this->successResponse(new LeaveRequestResource($updated), __('leave_management.messages.request_approved'));
    }

    #[OA\Post(
        path: '/api/v1/leave-management/requests/{id}/reject',
        operationId: 'leaveMgmtRejectRequest',
        summary: 'Reject a pending leave request',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Leave Management'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Leave request rejected')]
    )]
    public function reject(DecideLeaveRequestFormRequest $request, string $id): JsonResponse
    {
        $leaveRequest = LeaveRequest::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        Gate::authorize('decide', $leaveRequest);

        $updated = $this->decideHandler->handle(new DecideLeaveRequestCommand(
            tenantId: $this->tenantId(),
            leaveRequestId: $id,
            decision: 'reject',
            reason: (string) ($request->validated('reason') ?? 'Rejected'),
            actorUserId: $this->userId($request),
        ));

        return $this->successResponse(new LeaveRequestResource($updated), __('leave_management.messages.request_rejected'));
    }

    #[OA\Post(
        path: '/api/v1/leave-management/requests/{id}/cancel',
        operationId: 'leaveMgmtCancelRequest',
        summary: 'Cancel a pending or future approved leave request',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Leave Management'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Leave request cancelled')]
    )]
    public function cancel(DecideLeaveRequestFormRequest $request, string $id): JsonResponse
    {
        $leaveRequest = LeaveRequest::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        Gate::authorize('cancel', $leaveRequest);

        $updated = $this->decideHandler->handle(new DecideLeaveRequestCommand(
            tenantId: $this->tenantId(),
            leaveRequestId: $id,
            decision: 'cancel',
            reason: (string) ($request->validated('reason') ?? 'Cancelled'),
            actorUserId: $this->userId($request),
        ));

        return $this->successResponse(new LeaveRequestResource($updated), __('leave_management.messages.request_cancelled'));
    }
}
