<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ServiceManagement\Contracts\RmaServiceInterface;
use Modules\ServiceManagement\Models\DepotRepairRmaOrder;
use OpenApi\Attributes as OA;

/**
 * Depot repair Kanban board: one column per RMA status (cancelled excluded).
 */
final class RmaKanbanController extends Controller
{
    public function __construct(
        private readonly RmaServiceInterface $rmaService,
    ) {}

    #[OA\Get(
        path: '/api/v1/esm/rma-orders/board',
        operationId: 'getEsmRmaKanbanBoard',
        description: 'Returns one column per RMA status (cancelled excluded) for the depot repair Kanban board.',
        summary: 'Get RMA Kanban board',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM RMA Orders'],
        parameters: [
            new OA\Parameter(name: 'warranty_status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customer_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'include_closed', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [new OA\Response(response: 200, description: 'Board columns', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object'), new OA\Property(property: 'meta', type: 'object')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', DepotRepairRmaOrder::class);

        $columns = $this->rmaService->board([
            'warranty_status' => $request->filled('warranty_status') ? (string) $request->query('warranty_status') : null,
            'customer_id' => $request->filled('customer_id') ? (string) $request->query('customer_id') : null,
            'include_closed' => $request->boolean('include_closed'),
        ]);

        return response()->json([
            'data' => ['columns' => $columns],
            'meta' => ['generated_at' => now()->toIso8601String()],
        ]);
    }
}
