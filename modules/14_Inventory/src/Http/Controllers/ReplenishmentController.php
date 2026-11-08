<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Inventory\Http\Requests\ReorderRuleRequest;
use Modules\Inventory\Models\StockReorderRule;
use Modules\Inventory\Services\InventoryReplenishmentService;
use Modules\Inventory\Support\Quantity;
use Modules\Procurement\Models\PurchaseSuggestion;
use Modules\Procurement\Services\PurchaseSuggestionService;
use OpenApi\Attributes as OA;

/**
 * Replenishment: min/max reorder rules per item and warehouse, what they suggest now, and the hand-off of the
 * suggestions to Procurement (which adds the stock already on order and picks the supplier offer).
 */
#[OA\Tag(name: 'Inventory Replenishment', description: 'Reorder rules (min/max, reorder point), replenishment suggestions and hand-off to Procurement')]
final class ReplenishmentController extends Controller
{
    #[OA\Get(
        path: '/api/v1/inventory/reorder-rules',
        operationId: 'wmsListReorderRules',
        summary: 'List reorder rules',
        description: 'Min/max reorder rules, optionally of one warehouse or item.',
        tags: ['Inventory Replenishment'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/WmsWarehouseFilter'),
            new OA\Parameter(name: 'stock_item_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Rules', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WmsReorderRule'))])),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $rules = StockReorderRule::query()
            ->when($request->filled('warehouse_id'), static fn ($query) => $query->where('warehouse_id', (string) $request->query('warehouse_id')))
            ->when($request->filled('stock_item_id'), static fn ($query) => $query->where('stock_item_id', (string) $request->query('stock_item_id')))
            ->orderBy('created_at')
            ->get();

        return response()->json(['data' => $rules->map(static fn (StockReorderRule $rule): array => self::rule($rule))->all()]);
    }

    #[OA\Post(
        path: '/api/v1/inventory/reorder-rules',
        operationId: 'wmsStoreReorderRule',
        summary: 'Create a reorder rule',
        description: 'One rule per item and warehouse: minimum <= reorder point <= maximum, economic order quantity, lead time and preferred supplier.',
        tags: ['Inventory Replenishment'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WmsReorderRuleInput')),
        responses: [
            new OA\Response(response: 201, description: 'Rule created', content: new OA\JsonContent(ref: '#/components/schemas/WmsReorderRule')),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function store(ReorderRuleRequest $request): JsonResponse
    {
        $rule = StockReorderRule::create($request->validated());

        return response()->json(self::rule($rule->fresh() ?? $rule), Response::HTTP_CREATED);
    }

    #[OA\Put(
        path: '/api/v1/inventory/reorder-rules/{id}',
        operationId: 'wmsUpdateReorderRule',
        summary: 'Update a reorder rule',
        description: 'Changes the levels, order quantity, lead time or supplier of the rule; the item cannot change.',
        tags: ['Inventory Replenishment'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsRecordId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WmsReorderRuleInput')),
        responses: [
            new OA\Response(response: 200, description: 'Rule updated', content: new OA\JsonContent(ref: '#/components/schemas/WmsReorderRule')),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function update(string $id, ReorderRuleRequest $request): JsonResponse
    {
        $rule = StockReorderRule::query()->findOrFail($id);
        $rule->update($request->validated());

        return response()->json(self::rule($rule->fresh() ?? $rule));
    }

    #[OA\Delete(
        path: '/api/v1/inventory/reorder-rules/{id}',
        operationId: 'wmsDeleteReorderRule',
        summary: 'Delete a reorder rule',
        description: 'The item stops being replenished automatically in that warehouse; open purchase suggestions are not touched.',
        tags: ['Inventory Replenishment'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsRecordId')],
        responses: [
            new OA\Response(response: 204, description: 'Rule deleted'),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
        ]
    )]
    public function destroy(string $id): Response
    {
        // Rules are configuration, unique per item and warehouse: removed for good so the pair can get a new rule.
        StockReorderRule::query()->findOrFail($id)->forceDelete();

        return response()->noContent();
    }

    #[OA\Get(
        path: '/api/v1/inventory/replenishment/suggestions',
        operationId: 'wmsReplenishmentSuggestions',
        summary: 'Replenishment suggestions',
        description: 'For every reorder rule (optionally of one warehouse): the available stock (on hand minus reserved, outside quarantine and transit), whether it is at or below the reorder point and the quantity to order (economic order quantity, or up to the maximum when more is missing). Stock on order is added by Procurement at hand-off. With only_needed=true only the rules that need replenishment.',
        tags: ['Inventory Replenishment'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/WmsWarehouseFilter'),
            new OA\Parameter(name: 'only_needed', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Suggestions', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WmsReplenishmentSuggestion'))])),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        ]
    )]
    public function suggestions(Request $request, InventoryReplenishmentService $replenishment): JsonResponse
    {
        $rows = StockReorderRule::query()
            ->with('item')
            ->when($request->filled('warehouse_id'), static fn ($query) => $query->where('warehouse_id', (string) $request->query('warehouse_id')))
            ->orderBy('created_at')
            ->get()
            ->map(static fn (StockReorderRule $rule): ?array => $replenishment->suggestionFor($rule))
            ->filter()
            ->values();

        if ($request->boolean('only_needed')) {
            $rows = $rows->where('needs_replenishment', true)->values();
        }

        return response()->json(['data' => $rows->all()]);
    }

    #[OA\Post(
        path: '/api/v1/inventory/replenishment/hand-off',
        operationId: 'wmsReplenishmentHandOff',
        summary: 'Hand replenishment over to Procurement',
        description: 'Asks Procurement to generate its purchase suggestions from the reorder rules (PurchaseSuggestionService): available plus on-order stock at or below the reorder point gives a suggestion with the cheapest valid supplier offer (the preferred supplier first). Needs write access to both inventory and procurement. Returns the suggestions created now (existing open ones are not duplicated).',
        tags: ['Inventory Replenishment'],
        responses: [
            new OA\Response(response: 200, description: 'Purchase suggestions created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'created', type: 'integer', example: 3),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'source', type: 'string', example: 'reorder_rule'),
                    new OA\Property(property: 'source_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'quantity', type: 'string', example: '40.0000'),
                    new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'status', type: 'string', example: 'open'),
                ])),
            ])),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        ]
    )]
    public function handOff(PurchaseSuggestionService $suggestions, TenantContext $tenantContext): JsonResponse
    {
        $created = $suggestions->generate((string) $tenantContext->getTenantId());

        return response()->json([
            'created' => $created->count(),
            'data' => $created->map(static fn (PurchaseSuggestion $suggestion): array => [
                'id' => (string) $suggestion->id,
                'source' => (string) $suggestion->source,
                'source_id' => (string) $suggestion->source_id,
                'quantity' => Quantity::of((string) $suggestion->quantity),
                'supplier_id' => $suggestion->supplier_id,
                'status' => (string) $suggestion->status,
            ])->values()->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function rule(StockReorderRule $rule): array
    {
        return [
            'id' => (string) $rule->id,
            'warehouse_id' => (string) $rule->warehouse_id,
            'stock_item_id' => (string) $rule->stock_item_id,
            'min_stock_level' => Quantity::of((string) $rule->min_stock_level),
            'reorder_point' => Quantity::of((string) $rule->reorder_point),
            'max_stock_level' => Quantity::of((string) $rule->max_stock_level),
            'economic_order_quantity' => Quantity::of((string) $rule->economic_order_quantity),
            'lead_time_days' => (int) $rule->lead_time_days,
            'preferred_supplier_id' => $rule->preferred_supplier_id,
            'auto_trigger_requisition' => (bool) $rule->auto_trigger_requisition,
        ];
    }
}
