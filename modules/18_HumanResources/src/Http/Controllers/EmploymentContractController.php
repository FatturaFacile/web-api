<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\HumanResources\Http\Requests\SaveSalaryComponentRequest;
use Modules\HumanResources\Http\Requests\StoreEmploymentContractRequest;
use Modules\HumanResources\Http\Requests\UpdateEmploymentContractRequest;
use Modules\HumanResources\Http\Resources\EmploymentContractResource;
use Modules\HumanResources\Models\ContractSalaryComponent;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\EmploymentContract;
use Modules\HumanResources\Services\EmploymentContractService;
use OpenApi\Attributes as OA;

/**
 * Contract history of the employees with their salary components (HR roles only: module.access:hr).
 */
final class EmploymentContractController extends Controller
{
    public function __construct(private readonly EmploymentContractService $contracts) {}

    /**
     * List the contracts of an employee, newest first.
     */
    #[OA\Get(
        path: '/api/v1/hr/employees/{id}/contracts',
        operationId: 'listHrEmployeeContracts',
        description: 'Contract history of an employee (active, amended, expired) with the salary components of each contract, newest first.',
        summary: 'List employee contracts',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Contracts'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/HrRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Contracts', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
        ]
    )]
    public function index(string $id): JsonResponse
    {
        $employee = Employee::query()->findOrFail($id);
        $contracts = EmploymentContract::query()->where('employee_id', $employee->id)->with('salaryComponents')->orderByDesc('start_date')->get();

        return response()->json(['data' => EmploymentContractResource::collection($contracts)]);
    }

    /**
     * Start a new contract that supersedes the active one.
     */
    #[OA\Post(
        path: '/api/v1/hr/employees/{id}/contracts',
        operationId: 'storeHrEmployeeContract',
        description: 'Start a new contract (amendment, renewal, change of hours, level or pay). The active contract ends the day before and stays in the history, its open salary components end with it; the employee master data follows the new contract.',
        summary: 'Create employee contract',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HrContractStoreRequest')),
        tags: ['HR Contracts'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/HrRecordId')],
        responses: [
            new OA\Response(response: 201, description: 'Contract created', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function store(StoreEmploymentContractRequest $request, string $id): JsonResponse
    {
        $employee = Employee::query()->findOrFail($id);

        return response()->json(['data' => new EmploymentContractResource($this->contracts->create($employee, $request->validated()))], 201);
    }

    /**
     * Change the descriptive data of the active contract.
     */
    #[OA\Put(
        path: '/api/v1/hr/contracts/{id}',
        operationId: 'updateHrContract',
        description: 'Change title, end date, probation, collective agreement reference, level, terms or notes of the active contract. Pay, hours and start date change through a new contract.',
        summary: 'Update contract',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HrContractUpdateRequest')),
        tags: ['HR Contracts'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/HrRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Contract updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function update(UpdateEmploymentContractRequest $request, string $id): JsonResponse
    {
        $contract = EmploymentContract::query()->findOrFail($id);

        return response()->json(['data' => new EmploymentContractResource($this->contracts->update($contract, $request->validated()))]);
    }

    /**
     * Add a salary component to the active contract.
     */
    #[OA\Post(
        path: '/api/v1/hr/contracts/{id}/salary-components',
        operationId: 'storeHrContractSalaryComponent',
        description: 'Add a recurring salary component (wage type code, amount, monthly or annual, validity). Monthly components enter every payroll run of their validity as inputs of the employee.',
        summary: 'Add salary component',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HrSalaryComponentRequest')),
        tags: ['HR Contracts'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/HrRecordId')],
        responses: [
            new OA\Response(response: 201, description: 'Component created', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function storeComponent(SaveSalaryComponentRequest $request, string $id): JsonResponse
    {
        $contract = EmploymentContract::query()->findOrFail($id);

        return response()->json(['data' => EmploymentContractResource::component($this->contracts->addComponent($contract, $request->validated()))], 201);
    }

    /**
     * Change a salary component of the active contract.
     */
    #[OA\Put(
        path: '/api/v1/hr/salary-components/{id}',
        operationId: 'updateHrSalaryComponent',
        description: 'Change a salary component of the active contract. Components of superseded contracts stay unchanged in the history.',
        summary: 'Update salary component',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HrSalaryComponentRequest')),
        tags: ['HR Contracts'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/HrRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Component updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function updateComponent(SaveSalaryComponentRequest $request, string $id): JsonResponse
    {
        $component = ContractSalaryComponent::query()->findOrFail($id);

        return response()->json(['data' => EmploymentContractResource::component($this->contracts->updateComponent($component, $request->validated()))]);
    }

    /**
     * Remove a salary component of the active contract.
     */
    #[OA\Delete(
        path: '/api/v1/hr/salary-components/{id}',
        operationId: 'deleteHrSalaryComponent',
        description: 'Remove a salary component of the active contract (soft delete).',
        summary: 'Delete salary component',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Contracts'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/HrRecordId')],
        responses: [
            new OA\Response(response: 204, description: 'Component deleted'),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function destroyComponent(string $id): Response
    {
        $this->contracts->deleteComponent(ContractSalaryComponent::query()->findOrFail($id));

        return response()->noContent();
    }
}
