<?php

declare(strict_types=1);

namespace Modules\Logistics\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Logistics\Models\ConsignmentConsumptionReport;
use Modules\Logistics\Services\ConsignmentService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Logistics Consignment', description: 'Goods at third parties register, periodic consumption declarations and balance-sheet valuation')]
final class ConsignmentController extends Controller
{
    public function __construct(private readonly ConsignmentService $service) {}

    #[OA\Get(
        path: '/api/v1/logistics/consignment/ledger',
        summary: 'Register of goods at/for third parties',
        description: 'Requires permission logistics.ddt.view.',
        tags: ['Logistics Consignment'],
        parameters: [
            new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'partner_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 403, description: 'Missing logistics permission'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function ledger(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['sometimes', 'uuid'],
            'partner_id' => ['sometimes', 'uuid'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        return response()->json(['data' => $this->service->ledger($data['warehouse_id'] ?? null, $data['partner_id'] ?? null, $data['from'] ?? null, $data['to'] ?? null)]);
    }

    #[OA\Get(
        path: '/api/v1/logistics/consignment/valuation',
        summary: 'Inventory valuation split: balance sheet vs third-party owned',
        description: 'Requires permission logistics.ddt.view.',
        tags: ['Logistics Consignment'],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 403, description: 'Missing logistics permission'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function valuation(): JsonResponse
    {
        return response()->json(['data' => $this->service->inventoryValuation()]);
    }

    #[OA\Get(
        path: '/api/v1/logistics/consignment/consumption-reports',
        summary: 'List consumption declarations',
        description: 'Requires permission logistics.ddt.view.',
        tags: ['Logistics Consignment'],
        parameters: [
            new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 403, description: 'Missing logistics permission'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function reports(Request $request): JsonResponse
    {
        $query = ConsignmentConsumptionReport::query()->with('lines')
            ->when($request->query('warehouse_id'), fn ($q, $v) => $q->where('warehouse_id', $v))
            ->orderByDesc('created_at');

        return response()->json($query->paginate(min(100, (int) $request->query('per_page', 25))));
    }

    #[OA\Post(
        path: '/api/v1/logistics/consignment/consumption-reports',
        summary: 'Declare periodic consumption; generates a draft invoice (customer) or purchase order (vendor)',
        description: 'Requires permission logistics.consignment.reconcile.',
        tags: ['Logistics Consignment'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ConsumptionInput')),
        responses: [
            new OA\Response(response: 201, description: 'Success'),
            new OA\Response(response: 403, description: 'Missing logistics permission'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function declare(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'uuid'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        try {
            return response()->json(['data' => $this->service->declareConsumption($data, $data['lines'])], 201);
        } catch (InvalidArgumentException|DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
