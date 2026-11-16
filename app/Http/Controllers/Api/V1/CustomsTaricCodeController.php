<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customs\StoreCustomsTaricCodeRequest;
use App\Http\Resources\CustomsTaricCodeResource;
use App\Http\Responses\ApiResponse;
use App\Models\CustomsTaricCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class CustomsTaricCodeController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/catalog/customs-taric-codes',
        operationId: 'listCustomsTaricCodes',
        description: 'List the HS / CN / TARIC customs code catalog. `search` matches the code prefix or any localized description.',
        summary: 'List customs codes',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Customs'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'level', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['HS6', 'CN8', 'TARIC10'])),
            new OA\Parameter(name: 'is_active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 5, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Customs codes retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(5, (int) $request->query('per_page', 15)));

        $query = CustomsTaricCode::query()->orderBy('code');
        if (is_string($request->query('search')) && trim($request->query('search')) !== '') {
            $query->search($request->query('search'));
        }
        if (is_string($request->query('level')) && $request->query('level') !== '') {
            $query->where('level', $request->query('level'));
        }
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $records = $query->paginate($perPage);

        return $this->successResponse(
            data: CustomsTaricCodeResource::collection($records),
            message: __('api.list_retrieved', ['resource' => __('api.labels.customs_taric_code')]),
            meta: ['current_page' => $records->currentPage(), 'last_page' => $records->lastPage(), 'per_page' => $records->perPage(), 'total' => $records->total()]
        );
    }

    #[OA\Get(
        path: '/api/v1/catalog/customs-taric-codes/{id}',
        operationId: 'showCustomsTaricCode',
        summary: 'Show customs code',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Customs'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Customs code retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(
            new CustomsTaricCodeResource(CustomsTaricCode::findOrFail($id)),
            __('api.retrieved', ['resource' => __('api.labels.customs_taric_code')])
        );
    }

    #[OA\Post(
        path: '/api/v1/catalog/customs-taric-codes',
        operationId: 'createCustomsTaricCode',
        description: 'Create a customs code. Level and chapter are derived from the 6/8/10-digit code.',
        summary: 'Create customs code',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Customs'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code', 'description'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', pattern: '^\d{6}(\d{2}(\d{2})?)?$', example: '91012100'),
                    new OA\Property(property: 'description', type: 'object', example: ['it' => 'Orologi da polso', 'en' => 'Wrist-watches']),
                    new OA\Property(property: 'supplementary_unit', type: 'string', nullable: true, example: 'p/st'),
                    new OA\Property(property: 'duty_rate_notes', type: 'string', nullable: true),
                    new OA\Property(property: 'is_active', type: 'boolean', example: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Customs code created', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreCustomsTaricCodeRequest $request): JsonResponse
    {
        Gate::authorize('customs.manage');

        $record = CustomsTaricCode::create($request->validatedWithDerivedFields());

        return $this->successResponse(
            new CustomsTaricCodeResource($record),
            __('api.created', ['resource' => __('api.labels.customs_taric_code')]),
            Response::HTTP_CREATED
        );
    }

    #[OA\Put(
        path: '/api/v1/catalog/customs-taric-codes/{id}',
        operationId: 'updateCustomsTaricCode',
        summary: 'Update customs code',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Customs'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Customs code updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(StoreCustomsTaricCodeRequest $request, string $id): JsonResponse
    {
        Gate::authorize('customs.manage');

        $record = CustomsTaricCode::findOrFail($id);
        $record->update($request->validatedWithDerivedFields());

        return $this->successResponse(
            new CustomsTaricCodeResource($record->refresh()),
            __('api.updated', ['resource' => __('api.labels.customs_taric_code')])
        );
    }

    #[OA\Delete(
        path: '/api/v1/catalog/customs-taric-codes/{id}',
        operationId: 'deleteCustomsTaricCode',
        summary: 'Delete customs code',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Customs'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Customs code deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        Gate::authorize('customs.manage');

        CustomsTaricCode::findOrFail($id)->delete();

        return $this->successResponse(null, __('api.deleted', ['resource' => __('api.labels.customs_taric_code')]));
    }
}
