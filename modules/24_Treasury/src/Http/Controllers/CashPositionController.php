<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Treasury\Contracts\TreasuryPositionServiceInterface;
use OpenApi\Attributes as OA;

final class CashPositionController extends Controller
{
    public function __construct(
        private readonly TreasuryPositionServiceInterface $positionService
    ) {}

    /**
     * Returns the intraday cash position of the active tenant, optionally narrowed by company and currency.
     */
    #[OA\Get(
        path: '/api/v1/treasury/positions/intraday',
        operationId: 'getIntradayTreasuryPosition',
        description: 'Returns the intraday cash position of the active tenant, optionally narrowed by company and currency.',
        summary: 'Get intraday cash position',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Positions'],
        parameters: [
            new OA\Parameter(name: 'company_id', in: 'query', required: false, description: 'Filter by company', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'currency', in: 'query', required: false, description: 'Filter by currency', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function intraday(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $companyId = $request->query('company_id');
        $currency = $request->query('currency');

        $position = $this->positionService->getIntradayPosition($tenantId, $companyId, $currency);

        return response()->json($position);
    }

    /**
     * Calculates the liquidity buffer of the active tenant, optionally for a single company.
     */
    #[OA\Get(
        path: '/api/v1/treasury/positions/buffer',
        operationId: 'getLiquidityBufferTreasuryPositionsBuffer',
        description: 'Calculates the liquidity buffer of the active tenant, optionally for a single company.',
        summary: 'Get liquidity buffer',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Positions'],
        parameters: [
            new OA\Parameter(name: 'company_id', in: 'query', required: false, description: 'Filter by company', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'total_cash', type: 'number'), new OA\Property(property: 'available_cash', type: 'number'), new OA\Property(property: 'blocked_cash', type: 'number'), new OA\Property(property: 'overdraft_utilized', type: 'number'), new OA\Property(property: 'currency', type: 'string')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function liquidityBuffer(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $companyId = $request->query('company_id');

        $buffer = $this->positionService->calculateLiquidityBuffer($tenantId, $companyId);

        return response()->json($buffer);
    }
}
