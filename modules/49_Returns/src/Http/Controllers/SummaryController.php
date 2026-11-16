<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Returns\Http\Controllers\Concerns\InteractsWithReturns;
use Modules\Returns\Models\Rma;
use Modules\Returns\Services\ReturnsSummaryService;
use OpenApi\Attributes as OA;

/**
 * The returns dashboard.
 */
final class SummaryController extends Controller
{
    use InteractsWithReturns;

    public function __construct(private readonly ReturnsSummaryService $summary) {}

    #[OA\Get(path: '/api/v1/returns/summary', operationId: 'getReturnsSummary', description: 'Return rate by product (returned against invoiced quantity) and by reason, the ageing of the open returns, the value refunded and the open warranty claims and supplier returns, for the returns requested between from and to (default: the last twelve months).', summary: 'Returns summary', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], parameters: [
        new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
    ], responses: [
        new OA\Response(response: 200, description: 'Summary', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReturnsSummary')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsUnprocessable', response: 422),
    ])]
    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Rma::class);
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return response()->json(['data' => $this->summary->summarize($this->tenantId(), $filters['from'] ?? null, $filters['to'] ?? null)]);
    }
}
