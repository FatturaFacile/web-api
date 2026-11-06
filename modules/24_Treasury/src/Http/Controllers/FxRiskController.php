<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Treasury\Actions\PerformFxRevaluationAction;
use Modules\Treasury\Contracts\ForeignExchangeRiskServiceInterface;
use OpenApi\Attributes as OA;

final class FxRiskController extends Controller
{
    public function __construct(
        private readonly ForeignExchangeRiskServiceInterface $fxService,
        private readonly PerformFxRevaluationAction $revaluationAction
    ) {}

    /**
     * Computes the currency exposure of the active tenant at the given date.
     */
    #[OA\Get(
        path: '/api/v1/treasury/fx/exposure',
        operationId: 'getExposureTreasuryFx',
        description: 'Computes the currency exposure of the active tenant at the given date.',
        summary: 'Get FX exposure',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury FX'],
        parameters: [
            new OA\Parameter(name: 'as_of_date', in: 'query', required: false, description: 'Reference date', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'company_id', in: 'query', required: false, description: 'Filter by company', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function exposure(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $date = $request->query('as_of_date', now()->toDateString());
        $companyId = $request->query('company_id');

        $positions = $this->fxService->computeCurrencyExposure($tenantId, $date, $companyId);

        return response()->json($positions);
    }

    /**
     * Revalues the balances in a foreign currency at the given spot rate and period end date.
     */
    #[OA\Post(
        path: '/api/v1/treasury/fx/revalue',
        operationId: 'revalueTreasuryFx',
        description: 'Revalues the balances in a foreign currency at the given spot rate and period end date.',
        summary: 'Revalue FX balances',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury FX'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['currency', 'spot_rate'], properties: [new OA\Property(property: 'currency', type: 'string', minLength: 3, maxLength: 3), new OA\Property(property: 'period_end_date', type: 'string', format: 'date', nullable: true), new OA\Property(property: 'spot_rate', type: 'number'), new OA\Property(property: 'company_id')])),
        responses: [
            new OA\Response(response: 201, description: 'Revalue created', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryFxRevaluationModel')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function revalue(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $request->validate([
            'currency' => ['required', 'string', 'size:3'],
            'period_end_date' => ['nullable', 'date'],
            'spot_rate' => ['required', 'numeric', 'gt:0'],
        ]);

        $tenantId = $tenantContext->getTenantId();
        $currency = strtoupper((string) $request->input('currency'));
        $date = (string) $request->input('period_end_date', now()->toDateString());
        $spotRate = (float) $request->input('spot_rate');
        $companyId = $request->input('company_id');

        $revaluation = $this->revaluationAction->execute(
            $tenantId,
            $currency,
            $date,
            $spotRate,
            $companyId,
            (string) $request->user()?->id
        );

        return response()->json($revaluation, 201);
    }
}
