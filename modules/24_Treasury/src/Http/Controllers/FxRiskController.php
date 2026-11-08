<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Treasury\Contracts\ForeignExchangeRiskServiceInterface;
use Modules\Treasury\Http\Resources\FxRevaluationResource;
use Modules\Treasury\Services\BankFxRevaluationService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Currency exposure of the tenant and the period-end revaluation of its foreign-currency bank accounts.
 */
final class FxRiskController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ForeignExchangeRiskServiceInterface $fxService,
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
     * Period-end revaluation of the foreign-currency bank accounts, posted to the ledger.
     */
    #[OA\Post(
        path: '/api/v1/treasury/fx/revalue',
        operationId: 'revalueTreasuryFx',
        description: 'Revalues every active bank account in a foreign currency (optionally only one currency) at the period end: the foreign balance of its ledger account at the closing rate of the exchange-rate service (exchange_rates, latest rate on or before the date) against its book value in the accounting currency. The difference is posted as an unrealised gain (Dr bank / Cr fx_unrealised_gain_account_code) or loss (Dr fx_unrealised_loss_account_code / Cr bank), once per account and period end: a repeated call returns the revaluations already posted. Refused with 422 when a rate, a ledger account or the configured accounts are missing. The spot_rate of earlier versions is no longer accepted.',
        summary: 'Revalue FX bank balances',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury FX'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['period_end_date'], properties: [new OA\Property(property: 'period_end_date', type: 'string', format: 'date'), new OA\Property(property: 'currency', type: 'string', maxLength: 3, minLength: 3, nullable: true)])),
        responses: [
            new OA\Response(response: 201, description: 'Revaluations posted', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TreasuryBankFxRevaluationResource')),
            ])),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/TreasuryValidationError', response: 422),
        ]
    )]
    public function revalue(Request $request, TenantContext $tenantContext, BankFxRevaluationService $revaluations): JsonResponse
    {
        $data = $request->validate([
            'period_end_date' => ['required', 'date_format:Y-m-d'],
            'currency' => ['nullable', 'string', 'size:3', 'alpha'],
            'spot_rate' => ['prohibited'],
        ]);

        $result = $revaluations->revalue((string) $tenantContext->getTenantId(), $data['period_end_date'], $data['currency'] ?? null, (string) $request->user()?->getAuthIdentifier());

        return $this->successResponse(FxRevaluationResource::collection($result), 'FX revaluation posted', Response::HTTP_CREATED);
    }
}
