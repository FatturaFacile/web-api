<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response as HttpResponse;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Http\Requests\SaveWageTypeRequest;
use Modules\Payroll\Http\Resources\PayrollWageTypeResource;
use Modules\Payroll\Models\PayrollWageType;
use OpenApi\Attributes as OA;

/**
 * The wage type catalog of the company; system types keep their code and cannot be deleted.
 */
final class WageTypeController extends PayrollController
{
    /**
     * List wage types.
     */
    #[OA\Get(
        path: '/api/v1/payroll/wage-types',
        operationId: 'listPayrollWageTypes',
        description: 'Return the wage types of the company and the generic ones.',
        summary: 'List wage types',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        responses: [
            new OA\Response(response: 200, description: 'Wage types', content: new OA\JsonContent(ref: '#/components/schemas/PayrollWageTypePage')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PayrollWageType::class);

        return PayrollWageTypeResource::collection(PayrollWageType::query()->orderBy('sort_order')->orderBy('code')->gridPaginate($this->perPage($request, 100, 200)));
    }

    /**
     * Create a wage type.
     */
    #[OA\Post(
        path: '/api/v1/payroll/wage-types',
        operationId: 'storePayrollWageType',
        description: 'Create a wage type of the company.',
        summary: 'Create wage type',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollWageTypeRequest')),
        tags: ['Payroll'],
        responses: [
            new OA\Response(response: 201, description: 'Wage type created', content: new OA\JsonContent(ref: '#/components/schemas/PayrollWageTypeEnvelope')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function store(SaveWageTypeRequest $request): JsonResponse
    {
        $this->authorize('create', PayrollWageType::class);

        return (new PayrollWageTypeResource($this->refreshed(PayrollWageType::query()->create($request->validated()))))->response()->setStatusCode(201);
    }

    /**
     * Change a wage type.
     */
    #[OA\Put(
        path: '/api/v1/payroll/wage-types/{id}',
        operationId: 'updatePayrollWageType',
        description: 'Change a wage type of the company. The code of a system type cannot change.',
        summary: 'Update wage type',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollWageTypeRequest')),
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Wage type saved', content: new OA\JsonContent(ref: '#/components/schemas/PayrollWageTypeEnvelope')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function update(SaveWageTypeRequest $request, string $id): PayrollWageTypeResource
    {
        $wageType = PayrollWageType::query()->findOrFail($id);
        $this->authorize('update', $wageType);

        if ($wageType->is_system && $wageType->code !== $request->validated('code')) {
            throw new PayrollException('Il codice di un genere salariale di sistema non può essere modificato.');
        }

        $wageType->update($request->validated());

        return new PayrollWageTypeResource($wageType->refresh());
    }

    /**
     * Delete a wage type.
     */
    #[OA\Delete(
        path: '/api/v1/payroll/wage-types/{id}',
        operationId: 'deletePayrollWageType',
        description: 'Delete a wage type of the company. System types cannot be deleted: deactivate them instead.',
        summary: 'Delete wage type',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 204, description: 'Wage type deleted'),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function destroy(string $id): HttpResponse
    {
        $wageType = PayrollWageType::query()->findOrFail($id);
        $this->authorize('delete', $wageType);

        if ($wageType->is_system) {
            throw new PayrollException('I generi salariali di sistema non possono essere eliminati (disattivarli se non servono).');
        }

        $wageType->delete();

        return response()->noContent();
    }
}
