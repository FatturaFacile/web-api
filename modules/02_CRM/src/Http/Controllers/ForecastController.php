<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CRM\Contracts\ForecastServiceInterface;
use Modules\CRM\DTOs\GenerateForecastDTO;
use Modules\CRM\Http\Requests\GenerateForecastRequest;
use Modules\CRM\Http\Resources\ForecastResource;
use Modules\CRM\Models\Forecast;
use OpenApi\Attributes as OA;

final class ForecastController extends Controller
{
    public function __construct(
        private readonly ForecastServiceInterface $forecastService,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Returns the paginated list of forecasts of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/crm/forecasts',
        operationId: 'listCrmForecasts',
        description: 'Returns the paginated list of forecasts of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List forecasts',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Forecasts'],
        parameters: [
            new OA\Parameter(name: 'period', in: 'query', required: false, description: 'Filter by period', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of forecasts', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CRMForecastResource')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $query = Forecast::where('tenant_id', $tenant->id);

        if ($request->has('period')) {
            $query->where('period', $request->query('period'));
        }

        $forecasts = $query->orderByDesc('period')->paginate(25);

        return response()->json([
            'data' => ForecastResource::collection($forecasts),
            'meta' => [
                'current_page' => $forecasts->currentPage(),
                'total' => $forecasts->total(),
            ],
        ]);
    }

    /**
     * Generates the sales forecast of a period for a user.
     */
    #[OA\Post(
        path: '/api/v1/crm/forecasts/generate',
        operationId: 'generateCrmForecast',
        description: 'Generates the sales forecast of a period for a user.',
        summary: 'Generate sales forecast',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Forecasts'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CRMGenerateForecastRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Generate created', content: new OA\JsonContent(ref: '#/components/schemas/CRMForecastResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function generate(GenerateForecastRequest $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $actorId = (string) ($request->user()?->id ?? 'system');

        $forecast = $this->forecastService->generateForecast(new GenerateForecastDTO(
            tenantId: (string) $tenant->id,
            period: $request->input('period'),
            userId: $request->input('user_id'),
            currency: $request->input('currency', 'EUR'),
            quotaAmount: (float) $request->input('quota_amount', 0.0)
        ), $actorId);

        return response()->json(new ForecastResource($forecast), 201);
    }

    /**
     * Locks a sales forecast against further changes.
     */
    #[OA\Post(
        path: '/api/v1/crm/forecasts/{id}/lock',
        operationId: 'lockCrmForecast',
        description: 'Locks a sales forecast against further changes.',
        summary: 'Lock sales forecast',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Forecasts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the forecast', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/CRMForecastResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function lock(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $forecast = Forecast::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? 'system');

        $locked = $this->forecastService->lockForecast($forecast, $actorId);

        return response()->json(new ForecastResource($locked));
    }
}
