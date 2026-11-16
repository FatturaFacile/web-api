<?php

declare(strict_types=1);

namespace Modules\Logistics\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Modules\Logistics\Enums\DiscrepancyStatus;
use Modules\Logistics\Enums\TransferPurpose;
use Modules\Logistics\Models\StockTransferOrder;
use Modules\Logistics\Models\TransferDiscrepancy;
use Modules\Logistics\Services\StockTransferService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Logistics Transfers', description: 'Two-step stock transfers (origin -> transit -> destination) and discrepancy approval')]
final class StockTransferController extends Controller
{
    public function __construct(private readonly StockTransferService $service) {}

    #[OA\Get(
        path: '/api/v1/logistics/transfers',
        summary: 'List transfer orders',
        description: 'Requires permission logistics.transfers.manage.',
        tags: ['Logistics Transfers'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'purpose', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 403, description: 'Missing logistics permission'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $query = StockTransferOrder::query()->with('lines')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('purpose'), fn ($q, $v) => $q->where('purpose', $v))
            ->orderByDesc('created_at');

        return response()->json($query->paginate(min(100, (int) $request->query('per_page', 25))));
    }

    #[OA\Get(
        path: '/api/v1/logistics/transfers/{id}',
        summary: 'Get a transfer order',
        description: 'Requires permission logistics.transfers.manage.',
        tags: ['Logistics Transfers'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 403, description: 'Missing logistics permission'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => StockTransferOrder::query()->with(['lines', 'discrepancies'])->findOrFail($id)]);
    }

    #[OA\Post(
        path: '/api/v1/logistics/transfers',
        summary: 'Create a transfer order (draft)',
        description: 'Requires permission logistics.transfers.manage.',
        tags: ['Logistics Transfers'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TransferInput')),
        responses: [
            new OA\Response(response: 201, description: 'Success'),
            new OA\Response(response: 403, description: 'Missing logistics permission'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'purpose' => ['required', Rule::in(TransferPurpose::values())],
            'origin_warehouse_id' => ['required', 'uuid'],
            'destination_warehouse_id' => ['required', 'uuid'],
            'transit_warehouse_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->run(fn () => response()->json(['data' => $this->service->create($data, $data['lines'])], 201));
    }

    #[OA\Post(
        path: '/api/v1/logistics/transfers/{id}/ship',
        summary: 'Ship: origin -> transit location and automatic DDT',
        description: 'Requires permission logistics.transfers.manage.',
        tags: ['Logistics Transfers'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 403, description: 'Missing logistics permission'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function ship(Request $request, string $id): JsonResponse
    {
        $delivery = $request->validate([
            'carrier_name' => ['nullable', 'string', 'max:255'],
            'carrier_license_plate' => ['nullable', 'string', 'max:30'],
            'transport_by' => ['nullable', Rule::in(['sender', 'recipient', 'carrier'])],
            'carriage_terms' => ['nullable', Rule::in(['free', 'collect', 'free_with_charge'])],
            'goods_appearance' => ['nullable', 'string', 'max:100'],
            'packages_count' => ['nullable', 'integer', 'min:0'],
            'gross_weight_kg' => ['nullable', 'numeric', 'min:0'],
        ]);

        return $this->run(fn () => response()->json(['data' => $this->service->ship(StockTransferOrder::query()->findOrFail($id), $delivery)]));
    }

    #[OA\Post(
        path: '/api/v1/logistics/transfers/{id}/receive',
        summary: 'Receive: transit -> destination, with shortages/damages creating discrepancies',
        description: 'Requires permission logistics.transfers.manage.',
        tags: ['Logistics Transfers'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 403, description: 'Missing logistics permission'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function receive(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'lines' => ['sometimes', 'array'],
            'lines.*.line_id' => ['required', 'uuid'],
            'lines.*.received_qty' => ['required', 'numeric', 'min:0'],
            'lines.*.discrepancy_reason' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->run(fn () => response()->json(['data' => $this->service->receive(StockTransferOrder::query()->findOrFail($id), $data['lines'] ?? [])]));
    }

    #[OA\Post(
        path: '/api/v1/logistics/transfers/{id}/cancel',
        summary: 'Cancel a draft transfer',
        description: 'Requires permission logistics.transfers.manage.',
        tags: ['Logistics Transfers'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 403, description: 'Missing logistics permission'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function cancel(string $id): JsonResponse
    {
        return $this->run(fn () => response()->json(['data' => $this->service->cancel(StockTransferOrder::query()->findOrFail($id))]));
    }

    #[OA\Get(
        path: '/api/v1/logistics/transfer-discrepancies',
        summary: 'List transfer discrepancies',
        description: 'Requires permission logistics.transfers.manage.',
        tags: ['Logistics Transfers'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 403, description: 'Missing logistics permission'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function discrepancies(Request $request): JsonResponse
    {
        $query = TransferDiscrepancy::query()
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('created_at');

        return response()->json($query->paginate(min(100, (int) $request->query('per_page', 25))));
    }

    #[OA\Post(
        path: '/api/v1/logistics/transfer-discrepancies/{id}/resolve',
        summary: 'Approve a discrepancy resolution (write-off, carrier claim, return to origin)',
        description: 'Requires permission logistics.transfers.manage.',
        tags: ['Logistics Transfers'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DiscrepancyResolutionInput')),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 403, description: 'Missing logistics permission'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function resolveDiscrepancy(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['resolution' => ['required', Rule::in(DiscrepancyStatus::resolutions())]]);

        return $this->run(fn () => response()->json(['data' => $this->service->resolveDiscrepancy(
            TransferDiscrepancy::query()->findOrFail($id),
            DiscrepancyStatus::from($data['resolution']),
            $request->user() !== null ? (string) $request->user()->id : null,
        )]));
    }

    /**
     * @param  callable(): JsonResponse  $callback
     */
    private function run(callable $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (InvalidArgumentException|DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
