<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use Modules\HumanResources\Contracts\LeaveAndTimeServiceInterface;
use Modules\HumanResources\DTOs\SubmitLeaveRequestDTO;
use Modules\HumanResources\Exceptions\HumanResourcesException;
use Modules\HumanResources\Http\Requests\ReasonRequest;
use Modules\HumanResources\Http\Requests\SubmitOwnLeaveRequest;
use Modules\HumanResources\Http\Resources\EmployeeDocumentResource;
use Modules\HumanResources\Http\Resources\LeaveBalanceResource;
use Modules\HumanResources\Http\Resources\LeaveRequestResource;
use Modules\HumanResources\Models\Certification;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\EmployeeDocument;
use Modules\HumanResources\Models\LeaveBalance;
use Modules\HumanResources\Models\LeaveRequest;
use Modules\HumanResources\Models\TrainingRecord;
use Modules\HumanResources\Services\EmployeeDocumentService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Employee self-service: every member of the company reaches only the records of the employee linked to their user,
 * without HR module access. Pay data stays in the payroll self-service (payslips and certificates).
 */
final class EmployeeSelfServiceController extends Controller
{
    public function __construct(
        private readonly LeaveAndTimeServiceInterface $leave,
        private readonly EmployeeDocumentService $documents,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * The own employee record.
     */
    #[OA\Get(
        path: '/api/v1/hr/me',
        operationId: 'hrSelfServiceProfile',
        description: 'The employee record linked to the user in the active company (organisational data only).',
        summary: 'My employee record',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Self-Service'],
        responses: [
            new OA\Response(response: 200, description: 'Employee record', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
        ]
    )]
    public function profile(Request $request): JsonResponse
    {
        $employee = $this->ownEmployee($request);

        return response()->json(['data' => [
            'id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'full_name' => $employee->full_name,
            'work_email' => $employee->work_email,
            'department_id' => $employee->department_id,
            'position_id' => $employee->position_id,
            'manager_id' => $employee->manager_id,
            'employment_status' => $employee->employment_status,
            'employment_type' => $employee->employment_type,
            'hire_date' => $employee->hire_date?->format('Y-m-d'),
            'fte_ratio' => (string) $employee->fte_ratio,
        ]]);
    }

    /**
     * The own leave balances.
     */
    #[OA\Get(
        path: '/api/v1/hr/me/leave-balances',
        operationId: 'hrSelfServiceLeaveBalances',
        description: 'Leave balances of the own employee record.',
        summary: 'My leave balances',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Self-Service'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/HrYearFilter')],
        responses: [
            new OA\Response(response: 200, description: 'Balances', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
        ]
    )]
    public function leaveBalances(Request $request): JsonResponse
    {
        $employee = $this->ownEmployee($request);
        $balances = LeaveBalance::query()->where('employee_id', $employee->id)
            ->when($request->integer('year') > 0, static fn ($query) => $query->where('fiscal_year', $request->integer('year')))
            ->orderByDesc('fiscal_year')
            ->get();

        return response()->json(['data' => LeaveBalanceResource::collection($balances)]);
    }

    /**
     * The own leave requests.
     */
    #[OA\Get(
        path: '/api/v1/hr/me/leave-requests',
        operationId: 'hrSelfServiceLeaveRequests',
        description: 'Leave requests of the own employee record, newest first.',
        summary: 'My leave requests',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Self-Service'],
        responses: [
            new OA\Response(response: 200, description: 'Requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
        ]
    )]
    public function leaveRequests(Request $request): JsonResponse
    {
        $employee = $this->ownEmployee($request);

        return response()->json(['data' => LeaveRequestResource::collection(LeaveRequest::query()->where('employee_id', $employee->id)->orderByDesc('start_date')->get())]);
    }

    /**
     * Submit an own leave request.
     */
    #[OA\Post(
        path: '/api/v1/hr/me/leave-requests',
        operationId: 'hrSelfServiceSubmitLeave',
        description: 'Submit a leave request for the own employee record. It is approved through the HR_LEAVE_APPROVAL workflow when the company has one, by HR otherwise, immediately when the leave type needs no approval.',
        summary: 'Submit my leave request',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HrOwnLeaveRequest')),
        tags: ['HR Self-Service'],
        responses: [
            new OA\Response(response: 201, description: 'Request submitted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function submitLeave(SubmitOwnLeaveRequest $request): JsonResponse
    {
        $employee = $this->ownEmployee($request);

        try {
            $leaveRequest = $this->leave->submitLeaveRequest(new SubmitLeaveRequestDTO(
                tenantId: (string) $this->tenantContext->getTenantId(),
                employeeId: (string) $employee->id,
                leaveTypeId: (string) $request->validated('leave_type_id'),
                startDate: (string) $request->validated('start_date'),
                endDate: (string) $request->validated('end_date'),
                totalHours: (float) $request->validated('total_hours'),
                reason: $request->validated('reason'),
                medicalCertificateRef: $request->validated('medical_certificate_ref'),
            ), (string) $request->user()?->getAuthIdentifier());
        } catch (InvalidArgumentException $exception) {
            throw new HumanResourcesException($exception->getMessage());
        }

        return response()->json(['data' => new LeaveRequestResource($leaveRequest)], 201);
    }

    /**
     * Withdraw an own leave request.
     */
    #[OA\Post(
        path: '/api/v1/hr/me/leave-requests/{id}/cancel',
        operationId: 'hrSelfServiceCancelLeave',
        description: 'Withdraw an own pending request, or an own approved request that has not started yet.',
        summary: 'Cancel my leave request',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HrReasonRequest')),
        tags: ['HR Self-Service'],
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
    public function cancelLeave(ReasonRequest $request, string $id): JsonResponse
    {
        $employee = $this->ownEmployee($request);
        $leaveRequest = LeaveRequest::query()->where('employee_id', $employee->id)->findOrFail($id);

        return response()->json(['data' => new LeaveRequestResource($this->leave->cancelLeaveRequest($leaveRequest, (string) $request->validated('reason'), (string) $request->user()?->getAuthIdentifier()))]);
    }

    /**
     * The own training records and certifications.
     */
    #[OA\Get(
        path: '/api/v1/hr/me/training',
        operationId: 'hrSelfServiceTraining',
        description: 'Own training records and certifications with their expiry dates.',
        summary: 'My training and certifications',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Self-Service'],
        responses: [
            new OA\Response(response: 200, description: 'Training and certifications', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
        ]
    )]
    public function training(Request $request): JsonResponse
    {
        $employee = $this->ownEmployee($request);

        return response()->json(['data' => [
            'training_records' => TrainingRecord::query()->where('employee_id', $employee->id)->with('course:id,course_code,title,category,validity_months')->orderByDesc('assigned_date')->get()
                ->map(static fn (TrainingRecord $record): array => [
                    'id' => $record->id,
                    'course_code' => $record->course?->course_code,
                    'course_title' => $record->course?->title,
                    'category' => $record->course?->category,
                    'status' => $record->status,
                    'assigned_date' => $record->assigned_date?->format('Y-m-d'),
                    'scheduled_date' => $record->scheduled_date?->format('Y-m-d'),
                    'completed_date' => $record->completed_date?->format('Y-m-d'),
                ])->values(),
            'certifications' => Certification::query()->where('employee_id', $employee->id)->orderBy('expiry_date')->get()
                ->map(static fn (Certification $certification): array => [
                    'id' => $certification->id,
                    'code' => $certification->code,
                    'name' => $certification->name,
                    'issue_date' => $certification->issue_date?->format('Y-m-d'),
                    'expiry_date' => $certification->expiry_date?->format('Y-m-d'),
                    'status' => $certification->status,
                ])->values(),
        ]]);
    }

    /**
     * The own documents HR made visible.
     */
    #[OA\Get(
        path: '/api/v1/hr/me/documents',
        operationId: 'hrSelfServiceDocuments',
        description: 'Documents of the own personnel file that HR made visible to the employee.',
        summary: 'My documents',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Self-Service'],
        responses: [
            new OA\Response(response: 200, description: 'Documents', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
        ]
    )]
    public function documents(Request $request): JsonResponse
    {
        $employee = $this->ownEmployee($request);

        return response()->json(['data' => EmployeeDocumentResource::collection(
            EmployeeDocument::query()->where('employee_id', $employee->id)->where('visible_to_employee', true)->orderByDesc('created_at')->get()
        )]);
    }

    /**
     * Download an own visible document.
     */
    #[OA\Get(
        path: '/api/v1/hr/me/documents/{id}/download',
        operationId: 'hrSelfServiceDownloadDocument',
        description: 'Download a document of the own personnel file that HR made visible.',
        summary: 'Download my document',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Self-Service'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/HrRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'The file', content: new OA\MediaType(mediaType: 'application/octet-stream', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
        ]
    )]
    public function downloadDocument(Request $request, string $id): Response
    {
        $employee = $this->ownEmployee($request);
        $document = EmployeeDocument::query()->where('employee_id', $employee->id)->where('visible_to_employee', true)->findOrFail($id);

        return EmployeeDocumentController::fileResponse($this->documents, $document, $request->boolean('inline'));
    }

    /**
     * The employee record linked to the user in the active company; 403 when there is none.
     */
    private function ownEmployee(Request $request): Employee
    {
        $userId = $request->user()?->getAuthIdentifier();
        $employee = $userId === null ? null : Employee::query()->where('user_id', $userId)->first();

        if ($employee === null) {
            throw new AccessDeniedHttpException('No employee record is linked to your user in this company.');
        }

        return $employee;
    }
}
