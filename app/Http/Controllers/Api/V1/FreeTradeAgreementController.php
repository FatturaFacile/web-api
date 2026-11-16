<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customs\StoreFreeTradeAgreementRequest;
use App\Http\Resources\FreeTradeAgreementResource;
use App\Http\Responses\ApiResponse;
use App\Models\FreeTradeAgreement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class FreeTradeAgreementController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/catalog/free-trade-agreements',
        operationId: 'listFreeTradeAgreements',
        description: 'List free trade agreements (PEM / CH-EU, EU-UK, ...) with thresholds and origin declaration templates.',
        summary: 'List free trade agreements',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Customs'],
        parameters: [
            new OA\Parameter(name: 'is_active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 5, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Agreements retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(5, (int) $request->query('per_page', 15)));

        $query = FreeTradeAgreement::query()->orderBy('code');
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $records = $query->paginate($perPage);

        return $this->successResponse(
            data: FreeTradeAgreementResource::collection($records),
            message: __('api.list_retrieved', ['resource' => __('api.labels.free_trade_agreement')]),
            meta: ['current_page' => $records->currentPage(), 'last_page' => $records->lastPage(), 'per_page' => $records->perPage(), 'total' => $records->total()]
        );
    }

    #[OA\Get(
        path: '/api/v1/catalog/free-trade-agreements/{id}',
        operationId: 'showFreeTradeAgreement',
        summary: 'Show free trade agreement',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Customs'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Agreement retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(
            new FreeTradeAgreementResource(FreeTradeAgreement::findOrFail($id)),
            __('api.retrieved', ['resource' => __('api.labels.free_trade_agreement')])
        );
    }

    #[OA\Post(
        path: '/api/v1/catalog/free-trade-agreements',
        operationId: 'createFreeTradeAgreement',
        summary: 'Create free trade agreement',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Customs'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code', 'name', 'partner_countries', 'proof_types', 'declaration_templates', 'declaration_templates_no_auth'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', example: 'CH-EU-1972'),
                    new OA\Property(property: 'name', type: 'object', example: ['en' => 'Free Trade Agreement Switzerland - European Union']),
                    new OA\Property(property: 'partner_countries', type: 'array', items: new OA\Items(type: 'string', example: 'CH')),
                    new OA\Property(property: 'value_threshold_eur', type: 'number', format: 'float', nullable: true, example: 6000),
                    new OA\Property(property: 'value_threshold_chf', type: 'number', format: 'float', nullable: true, example: 10300),
                    new OA\Property(property: 'proof_types', type: 'array', items: new OA\Items(type: 'string', example: 'EUR1')),
                    new OA\Property(property: 'supports_approved_exporter', type: 'boolean'),
                    new OA\Property(property: 'supports_rex', type: 'boolean'),
                    new OA\Property(property: 'declaration_templates', type: 'object', description: 'Per-locale templates using {customs_auth_number} and {preferential_origin}'),
                    new OA\Property(property: 'declaration_templates_no_auth', type: 'object', description: 'Per-locale templates using {preferential_origin}'),
                    new OA\Property(property: 'notes', type: 'string', nullable: true),
                    new OA\Property(property: 'is_active', type: 'boolean'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Agreement created', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreFreeTradeAgreementRequest $request): JsonResponse
    {
        Gate::authorize('customs.manage');

        $record = FreeTradeAgreement::create($request->validated());

        return $this->successResponse(
            new FreeTradeAgreementResource($record),
            __('api.created', ['resource' => __('api.labels.free_trade_agreement')]),
            Response::HTTP_CREATED
        );
    }

    #[OA\Put(
        path: '/api/v1/catalog/free-trade-agreements/{id}',
        operationId: 'updateFreeTradeAgreement',
        summary: 'Update free trade agreement',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Customs'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Agreement updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(StoreFreeTradeAgreementRequest $request, string $id): JsonResponse
    {
        Gate::authorize('customs.manage');

        $record = FreeTradeAgreement::findOrFail($id);
        $record->update($request->validated());

        return $this->successResponse(
            new FreeTradeAgreementResource($record->refresh()),
            __('api.updated', ['resource' => __('api.labels.free_trade_agreement')])
        );
    }

    #[OA\Delete(
        path: '/api/v1/catalog/free-trade-agreements/{id}',
        operationId: 'deleteFreeTradeAgreement',
        summary: 'Delete free trade agreement',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Customs'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Agreement deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        Gate::authorize('customs.manage');

        FreeTradeAgreement::findOrFail($id)->delete();

        return $this->successResponse(null, __('api.deleted', ['resource' => __('api.labels.free_trade_agreement')]));
    }
}
