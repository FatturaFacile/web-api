<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PortalAccessToken;
use App\Services\Portal\PortalTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\PortalMovementRequest;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseBin;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The stock a customer keeps in the warehouses it owns, seen through its portal link. Only `dashboard` links reach
 * these pages: a link for one quote or invoice opens that document and nothing else.
 */
#[OA\Tag(name: 'Customer Portal Stock', description: 'Stock, history and movement requests of a customer, through its magic link')]
final class CustomerPortalStockController extends Controller
{
    public function __construct(
        private readonly PortalTokenService $tokens
    ) {}

    #[OA\Get(
        path: '/api/v1/portal/stock/{token}',
        operationId: 'getPortalStock',
        summary: 'Stock of the customer',
        description: 'Quantities per warehouse, item and lot, in the warehouses the customer owns. Public endpoint: the token is the credential.',
        security: [],
        tags: ['Customer Portal Stock'],
        parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Stock', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PortalStockLine'))])),
            new OA\Response(response: 403, description: 'This link does not open the stock pages'),
            new OA\Response(response: 404, description: 'Unknown link'),
            new OA\Response(response: 410, description: 'The link has expired'),
        ]
    )]
    public function stock(string $token): JsonResponse
    {
        $access = $this->access($token);
        $this->tokens->logActivity($access, 'portal_stock_viewed');

        $quants = StockQuant::query()
            ->with(['item', 'lot', 'bin.rack.aisle.zone'])
            ->whereIn('warehouse_bin_id', $this->binsOf($access))
            ->where('quantity_on_hand', '>', 0)
            ->get();

        $lines = $quants
            ->groupBy(static fn (StockQuant $quant): string => $quant->bin?->rack?->aisle?->zone?->warehouse_id.'|'.$quant->stock_item_id.'|'.$quant->lot_id)
            ->map(static function (Collection $group): array {
                $first = $group->first();
                $onHand = $group->reduce(static fn (string $sum, StockQuant $quant): string => bcadd($sum, (string) $quant->quantity_on_hand, 4), '0');
                $reserved = $group->reduce(static fn (string $sum, StockQuant $quant): string => bcadd($sum, (string) $quant->quantity_reserved, 4), '0');

                return [
                    'warehouse_id' => $first->bin?->rack?->aisle?->zone?->warehouse_id,
                    'stock_item_id' => $first->stock_item_id,
                    'sku' => $first->item?->sku,
                    'name' => $first->item?->name,
                    'lot_number' => $first->lot?->lot_number,
                    'expiration_date' => $first->lot?->expiration_date?->toDateString(),
                    'quantity_on_hand' => $onHand,
                    'quantity_reserved' => $reserved,
                    'quantity_available' => bcsub($onHand, $reserved, 4),
                ];
            })
            ->values();

        return response()->json(['data' => $lines]);
    }

    #[OA\Get(
        path: '/api/v1/portal/stock-movements/{token}',
        operationId: 'getPortalStockMovements',
        summary: 'Movement history of the customer',
        description: 'Movements in and out of the warehouses the customer owns, newest first.',
        security: [],
        tags: ['Customer Portal Stock'],
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated movements', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PortalStockMovement'))])),
            new OA\Response(response: 403, description: 'This link does not open the stock pages'),
            new OA\Response(response: 404, description: 'Unknown link'),
            new OA\Response(response: 410, description: 'The link has expired'),
        ]
    )]
    public function movements(string $token, Request $request): JsonResponse
    {
        $access = $this->access($token);
        $bins = $this->binsOf($access);

        $movements = StockMovement::query()
            ->with(['item', 'lot'])
            ->where(static fn ($query) => $query->whereIn('source_location_id', $bins)->orWhereIn('destination_location_id', $bins))
            ->orderByDesc('executed_at')
            ->gridPaginate(min(100, (int) $request->query('per_page', 25)));

        return response()->json([
            'data' => collect($movements->items())->map(static fn (StockMovement $movement): array => [
                'id' => $movement->id,
                'executed_at' => $movement->executed_at?->toIso8601String(),
                'direction' => in_array($movement->destination_location_id, $bins, true) ? (in_array($movement->source_location_id, $bins, true) ? 'internal' : 'in') : 'out',
                'sku' => $movement->item?->sku,
                'name' => $movement->item?->name,
                'lot_number' => $movement->lot?->lot_number,
                'quantity' => (string) $movement->quantity,
            ]),
            'meta' => ['current_page' => $movements->currentPage(), 'last_page' => $movements->lastPage(), 'total' => $movements->total()],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/portal/movement-requests/{token}',
        operationId: 'listPortalMovementRequests',
        summary: 'Movement requests of the customer',
        security: [],
        tags: ['Customer Portal Stock'],
        parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Requests', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PortalMovementRequest'))])),
            new OA\Response(response: 403, description: 'This link does not open the stock pages'),
            new OA\Response(response: 404, description: 'Unknown link'),
            new OA\Response(response: 410, description: 'The link has expired'),
        ]
    )]
    public function requests(string $token): JsonResponse
    {
        $access = $this->access($token);

        return response()->json(['data' => PortalMovementRequest::query()->where('contact_id', $access->contact_id)->orderByDesc('created_at')->get()]);
    }

    #[OA\Post(
        path: '/api/v1/portal/movement-requests/{token}',
        operationId: 'storePortalMovementRequest',
        summary: 'Ask for a movement',
        description: 'A withdrawal, return or transfer request on stock the customer owns. It waits for the warehouse to answer.',
        security: [],
        tags: ['Customer Portal Stock'],
        parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['warehouse_id', 'stock_item_id', 'request_type', 'quantity'],
            properties: [
                new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'lot_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'request_type', type: 'string', enum: ['withdrawal', 'return', 'transfer']),
                new OA\Property(property: 'quantity', type: 'number'),
                new OA\Property(property: 'requested_date', type: 'string', format: 'date'),
                new OA\Property(property: 'notes', type: 'string'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Request stored', content: new OA\JsonContent(ref: '#/components/schemas/PortalMovementRequest')),
            new OA\Response(response: 403, description: 'This link does not open the stock pages'),
            new OA\Response(response: 404, description: 'Unknown link'),
            new OA\Response(response: 410, description: 'The link has expired'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function storeRequest(string $token, Request $request): JsonResponse
    {
        $access = $this->access($token);
        $tenantId = (string) $access->tenant_id;

        $validated = $request->validate([
            'warehouse_id' => ['required', 'uuid', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)->where('owner_contact_id', $access->contact_id)],
            'stock_item_id' => ['required', 'uuid', Rule::exists('stock_items', 'id')->where('tenant_id', $tenantId)],
            'lot_id' => ['nullable', 'uuid', Rule::exists('stock_lots', 'id')->where('tenant_id', $tenantId)->where('stock_item_id', $request->input('stock_item_id'))],
            'request_type' => ['required', 'string', 'in:withdrawal,return,transfer'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'requested_date' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $movementRequest = PortalMovementRequest::create($validated + ['tenant_id' => $tenantId, 'contact_id' => $access->contact_id, 'status' => 'pending']);
        $this->tokens->logActivity($access, 'portal_movement_requested', PortalMovementRequest::class, (string) $movementRequest->id);

        return response()->json($movementRequest, 201);
    }

    private function access(string $token): PortalAccessToken
    {
        $access = $this->tokens->resolveToken($token);

        if ($access->token_type !== 'dashboard') {
            throw new AccessDeniedHttpException('This link does not open the stock pages.');
        }

        return $access;
    }

    /**
     * @return list<string>
     */
    private function binsOf(PortalAccessToken $access): array
    {
        $warehouses = Warehouse::query()->where('owner_contact_id', $access->contact_id)->pluck('id');

        return WarehouseBin::query()
            ->whereHas('rack.aisle.zone', static fn ($zone) => $zone->whereIn('warehouse_id', $warehouses))
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }
}
