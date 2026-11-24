<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\HandlesLookupCrud;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreUnitOfMeasureRequest;
use App\Models\UnitOfMeasure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class UnitOfMeasureController extends Controller
{
    use HandlesLookupCrud {
        index as lookupIndex;
        show as lookupShow;
        destroy as lookupDestroy;
    }

    protected function modelClass(): string
    {
        return UnitOfMeasure::class;
    }

    protected function label(): string
    {
        return __('api.labels.unit_of_measure');
    }

    #[OA\Get(
        path: '/api/v1/catalog/units',
        operationId: 'getUnitsOfMeasure',
        description: 'List units of measure in catalog (e.g. KG, MT, PZ, ORE).',
        summary: 'List units of measure',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Units of measure retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        return $this->lookupIndex($request);
    }

    #[OA\Post(
        path: '/api/v1/catalog/units',
        operationId: 'storeUnitOfMeasure',
        description: 'Create a new unit of measure in catalog.',
        summary: 'Create unit of measure',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Catalog'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code', 'name'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', example: 'PZ'),
                    new OA\Property(property: 'name', type: 'string', example: 'Pezzo'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Unit created successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreUnitOfMeasureRequest $request): JsonResponse
    {
        return $this->createRecord($request->validated());
    }

    #[OA\Get(
        path: '/api/v1/catalog/units/{id}',
        operationId: 'getUnitOfMeasure',
        description: 'Get details of a specific unit of measure.',
        summary: 'Get unit of measure',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Unit retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Unit not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->lookupShow($id);
    }

    #[OA\Put(
        path: '/api/v1/catalog/units/{id}',
        operationId: 'updateUnitOfMeasure',
        description: 'Update unit of measure details.',
        summary: 'Update unit of measure',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'code', type: 'string'),
                    new OA\Property(property: 'name', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Unit updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Unit not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(StoreUnitOfMeasureRequest $request, string $id): JsonResponse
    {
        return $this->updateRecord($id, $request->validated());
    }

    #[OA\Delete(
        path: '/api/v1/catalog/units/{id}',
        operationId: 'deleteUnitOfMeasure',
        description: 'Delete a unit of measure from catalog.',
        summary: 'Delete unit of measure',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Unit deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Unit not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        return $this->lookupDestroy($id);
    }
}
