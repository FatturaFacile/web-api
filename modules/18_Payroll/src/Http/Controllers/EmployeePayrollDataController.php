<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response as HttpResponse;
use Modules\Payroll\Http\Requests\ListEmployeeDocumentsRequest;
use Modules\Payroll\Http\Requests\SaveAbsenceRequest;
use Modules\Payroll\Http\Requests\SaveEmployeeChildRequest;
use Modules\Payroll\Http\Requests\SaveEmployeeProfileRequest;
use Modules\Payroll\Http\Requests\SavePayrollInputRequest;
use Modules\Payroll\Http\Resources\PayrollAbsenceResource;
use Modules\Payroll\Http\Resources\PayrollEmployeeChildResource;
use Modules\Payroll\Http\Resources\PayrollEmployeeProfileResource;
use Modules\Payroll\Http\Resources\PayrollInputResource;
use Modules\Payroll\Models\PayrollAbsence;
use Modules\Payroll\Models\PayrollEmployeeChild;
use Modules\Payroll\Models\PayrollEmployeeProfile;
use Modules\Payroll\Models\PayrollInput;
use OpenApi\Attributes as OA;

/**
 * What the payroll keeps about an employee: profile, variable inputs, absences and children.
 */
final class EmployeePayrollDataController extends PayrollController
{
    /**
     * List payroll profiles.
     */
    #[OA\Get(
        path: '/api/v1/payroll/employee-profiles',
        operationId: 'listPayrollEmployeeProfiles',
        description: 'Return the payroll profiles of the employees of the company, with the decrypted sensitive fields.',
        summary: 'List payroll profiles',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollEmployeeFilter')],
        responses: [
            new OA\Response(response: 200, description: 'Payroll profiles', content: new OA\JsonContent(ref: '#/components/schemas/PayrollEmployeeProfilePage')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        ]
    )]
    public function profiles(ListEmployeeDocumentsRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PayrollEmployeeProfile::class);

        return PayrollEmployeeProfileResource::collection(PayrollEmployeeProfile::query()
            ->when($request->validated('employee_id') !== null, static fn ($query) => $query->where('employee_id', $request->validated('employee_id')))
            ->paginate((int) $request->validated('per_page', 50)));
    }

    /**
     * Show the payroll profile of an employee.
     */
    #[OA\Get(
        path: '/api/v1/payroll/employee-profiles/{employeeId}',
        operationId: 'showPayrollEmployeeProfile',
        description: 'Return the payroll profile of an employee of the company, or null when it has not been created yet.',
        summary: 'Show payroll profile',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollEmployeeId')],
        responses: [
            new OA\Response(response: 200, description: 'Payroll profile', content: new OA\JsonContent(ref: '#/components/schemas/PayrollEmployeeProfileNullableEnvelope')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        ]
    )]
    public function showProfile(string $employeeId): JsonResponse
    {
        $this->authorize('viewAny', PayrollEmployeeProfile::class);
        $employee = $this->employee($employeeId);
        $profile = PayrollEmployeeProfile::query()->where('employee_id', $employee->id)->first();

        return response()->json(['data' => $profile === null ? null : new PayrollEmployeeProfileResource($profile)]);
    }

    /**
     * Create or change the payroll profile of an employee.
     */
    #[OA\Put(
        path: '/api/v1/payroll/employee-profiles/{employeeId}',
        operationId: 'savePayrollEmployeeProfile',
        description: 'Create or change the payroll profile of an employee of the company. Identification number, IBAN and address are stored encrypted.',
        summary: 'Save payroll profile',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollEmployeeProfileRequest')),
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollEmployeeId')],
        responses: [
            new OA\Response(response: 200, description: 'Payroll profile saved', content: new OA\JsonContent(ref: '#/components/schemas/PayrollEmployeeProfileEnvelope')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function saveProfile(SaveEmployeeProfileRequest $request, string $employeeId): PayrollEmployeeProfileResource
    {
        $this->authorize('create', PayrollEmployeeProfile::class);
        $employee = $this->employee($employeeId);

        $profile = PayrollEmployeeProfile::query()->firstOrNew(['employee_id' => $employee->id]);
        $profile->fill($request->validated())->save();

        $profile->refresh()->wasRecentlyCreated = false;

        return new PayrollEmployeeProfileResource($profile);
    }

    /**
     * List variable inputs.
     */
    #[OA\Get(
        path: '/api/v1/payroll/inputs',
        operationId: 'listPayrollInputs',
        description: 'Return the variable inputs, optionally of one employee and one period.',
        summary: 'List payroll inputs',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/PayrollEmployeeFilter'),
            new OA\Parameter(ref: '#/components/parameters/PayrollYearFilter'),
            new OA\Parameter(ref: '#/components/parameters/PayrollMonthFilter'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Payroll inputs', content: new OA\JsonContent(ref: '#/components/schemas/PayrollInputPage')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function inputs(ListEmployeeDocumentsRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PayrollInput::class);

        return PayrollInputResource::collection(PayrollInput::query()
            ->when($request->validated('employee_id') !== null, static fn ($query) => $query->where('employee_id', $request->validated('employee_id')))
            ->when($request->validated('year') !== null, static fn ($query) => $query->where('year', (int) $request->validated('year')))
            ->when($request->validated('month') !== null, static fn ($query) => $query->where('month', (int) $request->validated('month')))
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->paginate((int) $request->validated('per_page', 50)));
    }

    /**
     * Add a variable input.
     */
    #[OA\Post(
        path: '/api/v1/payroll/inputs',
        operationId: 'storePayrollInput',
        description: 'Add a variable input for an employee and a month. The month must not be closed.',
        summary: 'Create payroll input',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollInputRequest')),
        tags: ['Payroll'],
        responses: [
            new OA\Response(response: 201, description: 'Input created', content: new OA\JsonContent(ref: '#/components/schemas/PayrollInputEnvelope')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PayrollConflict', response: 409),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function storeInput(SavePayrollInputRequest $request): JsonResponse
    {
        $this->authorize('create', PayrollInput::class);
        $this->employee((string) $request->validated('employee_id'));

        return (new PayrollInputResource($this->refreshed(PayrollInput::query()->create($request->validated()))))->response()->setStatusCode(201);
    }

    /**
     * Change a variable input.
     */
    #[OA\Put(
        path: '/api/v1/payroll/inputs/{id}',
        operationId: 'updatePayrollInput',
        description: 'Change a variable input. The month must not be closed.',
        summary: 'Update payroll input',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollInputRequest')),
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Input saved', content: new OA\JsonContent(ref: '#/components/schemas/PayrollInputEnvelope')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PayrollConflict', response: 409),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function updateInput(SavePayrollInputRequest $request, string $id): PayrollInputResource
    {
        $input = PayrollInput::query()->findOrFail($id);
        $this->authorize('update', $input);
        $this->employee((string) $request->validated('employee_id'));
        $input->update($request->validated());

        return new PayrollInputResource($input->refresh());
    }

    /**
     * Delete a variable input.
     */
    #[OA\Delete(
        path: '/api/v1/payroll/inputs/{id}',
        operationId: 'deletePayrollInput',
        description: 'Delete a variable input. The month must not be closed.',
        summary: 'Delete payroll input',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 204, description: 'Input deleted'),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PayrollConflict', response: 409),
        ]
    )]
    public function destroyInput(string $id): HttpResponse
    {
        $input = PayrollInput::query()->findOrFail($id);
        $this->authorize('delete', $input);
        $input->delete();

        return response()->noContent();
    }

    /**
     * List absences.
     */
    #[OA\Get(
        path: '/api/v1/payroll/absences',
        operationId: 'listPayrollAbsences',
        description: 'Return the absences the payroll reads, optionally of one employee.',
        summary: 'List payroll absences',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollEmployeeFilter')],
        responses: [
            new OA\Response(response: 200, description: 'Absences', content: new OA\JsonContent(ref: '#/components/schemas/PayrollAbsencePage')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function absences(ListEmployeeDocumentsRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PayrollAbsence::class);

        return PayrollAbsenceResource::collection(PayrollAbsence::query()
            ->when($request->validated('employee_id') !== null, static fn ($query) => $query->where('employee_id', $request->validated('employee_id')))
            ->orderByDesc('start_date')
            ->paginate((int) $request->validated('per_page', 50)));
    }

    /**
     * Add an absence.
     */
    #[OA\Post(
        path: '/api/v1/payroll/absences',
        operationId: 'storePayrollAbsence',
        description: 'Add an absence of an employee. Only unpaid absences change the pay; sickness, accident and maternity raise a warning on Italian payslips.',
        summary: 'Create payroll absence',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollAbsenceRequest')),
        tags: ['Payroll'],
        responses: [
            new OA\Response(response: 201, description: 'Absence created', content: new OA\JsonContent(ref: '#/components/schemas/PayrollAbsenceEnvelope')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function storeAbsence(SaveAbsenceRequest $request): JsonResponse
    {
        $this->authorize('create', PayrollAbsence::class);
        $this->employee((string) $request->validated('employee_id'));

        return (new PayrollAbsenceResource($this->refreshed(PayrollAbsence::query()->create($request->validated()))))->response()->setStatusCode(201);
    }

    /**
     * Change an absence.
     */
    #[OA\Put(
        path: '/api/v1/payroll/absences/{id}',
        operationId: 'updatePayrollAbsence',
        description: 'Change an absence of an employee.',
        summary: 'Update payroll absence',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollAbsenceRequest')),
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Absence saved', content: new OA\JsonContent(ref: '#/components/schemas/PayrollAbsenceEnvelope')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function updateAbsence(SaveAbsenceRequest $request, string $id): PayrollAbsenceResource
    {
        $absence = PayrollAbsence::query()->findOrFail($id);
        $this->authorize('update', $absence);
        $this->employee((string) $request->validated('employee_id'));
        $absence->update($request->validated());

        return new PayrollAbsenceResource($absence->refresh());
    }

    /**
     * Delete an absence.
     */
    #[OA\Delete(
        path: '/api/v1/payroll/absences/{id}',
        operationId: 'deletePayrollAbsence',
        description: 'Delete an absence of an employee.',
        summary: 'Delete payroll absence',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 204, description: 'Absence deleted'),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        ]
    )]
    public function destroyAbsence(string $id): HttpResponse
    {
        $absence = PayrollAbsence::query()->findOrFail($id);
        $this->authorize('delete', $absence);
        $absence->delete();

        return response()->noContent();
    }

    /**
     * List children.
     */
    #[OA\Get(
        path: '/api/v1/payroll/children',
        operationId: 'listPayrollEmployeeChildren',
        description: 'Return the children of the employees for the family allowances, optionally of one employee.',
        summary: 'List employee children',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollEmployeeFilter')],
        responses: [
            new OA\Response(response: 200, description: 'Children', content: new OA\JsonContent(ref: '#/components/schemas/PayrollEmployeeChildPage')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function children(ListEmployeeDocumentsRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PayrollEmployeeChild::class);

        return PayrollEmployeeChildResource::collection(PayrollEmployeeChild::query()
            ->when($request->validated('employee_id') !== null, static fn ($query) => $query->where('employee_id', $request->validated('employee_id')))
            ->orderBy('date_of_birth')
            ->paginate((int) $request->validated('per_page', 50)));
    }

    /**
     * Add a child.
     */
    #[OA\Post(
        path: '/api/v1/payroll/children',
        operationId: 'storePayrollEmployeeChild',
        description: 'Add a child of an employee. The names are stored encrypted.',
        summary: 'Create employee child',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollEmployeeChildRequest')),
        tags: ['Payroll'],
        responses: [
            new OA\Response(response: 201, description: 'Child created', content: new OA\JsonContent(ref: '#/components/schemas/PayrollEmployeeChildEnvelope')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function storeChild(SaveEmployeeChildRequest $request): JsonResponse
    {
        $this->authorize('create', PayrollEmployeeChild::class);
        $this->employee((string) $request->validated('employee_id'));

        return (new PayrollEmployeeChildResource($this->refreshed(PayrollEmployeeChild::query()->create($request->validated()))))->response()->setStatusCode(201);
    }

    /**
     * Change a child.
     */
    #[OA\Put(
        path: '/api/v1/payroll/children/{id}',
        operationId: 'updatePayrollEmployeeChild',
        description: 'Change a child of an employee.',
        summary: 'Update employee child',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollEmployeeChildRequest')),
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Child saved', content: new OA\JsonContent(ref: '#/components/schemas/PayrollEmployeeChildEnvelope')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function updateChild(SaveEmployeeChildRequest $request, string $id): PayrollEmployeeChildResource
    {
        $child = PayrollEmployeeChild::query()->findOrFail($id);
        $this->authorize('update', $child);
        $this->employee((string) $request->validated('employee_id'));
        $child->update($request->validated());

        return new PayrollEmployeeChildResource($child->refresh());
    }

    /**
     * Delete a child.
     */
    #[OA\Delete(
        path: '/api/v1/payroll/children/{id}',
        operationId: 'deletePayrollEmployeeChild',
        description: 'Delete a child of an employee.',
        summary: 'Delete employee child',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 204, description: 'Child deleted'),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        ]
    )]
    public function destroyChild(string $id): HttpResponse
    {
        $child = PayrollEmployeeChild::query()->findOrFail($id);
        $this->authorize('delete', $child);
        $child->delete();

        return response()->noContent();
    }
}
