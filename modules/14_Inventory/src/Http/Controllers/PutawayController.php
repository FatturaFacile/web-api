<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Inventory\Http\Requests\PutawayRequest;
use Modules\Inventory\Http\Requests\PutawayRuleRequest;
use Modules\Inventory\Http\Resources\StockMovementResource;
use Modules\Inventory\Models\PutawayRule;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Services\PutawayService;
use Modules\Inventory\Support\Quantity;
use OpenApi\Attributes as OA;

/**
 * Directed putaway: the rules that say where incoming stock is stored (manager decision), the suggestion of bins for
 * a quantity and the move that stores it.
 */
#[OA\Tag(name: 'Inventory Putaway', description: 'Putaway rules, bin suggestions with capacity checks and putaway moves')]
final class PutawayController extends Controller
{
    #[OA\Get(
        path: '/api/v1/inventory/putaway-rules',
        operationId: 'wmsListPutawayRules',
        summary: 'List putaway rules',
        description: 'Putaway rules of the company, optionally of one warehouse, in the order they are tried: item rules, category rules, generic rules, then priority.',
        tags: ['Inventory Putaway'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsWarehouseFilter')],
        responses: [
            new OA\Response(response: 200, description: 'Putaway rules', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WmsPutawayRule'))])),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $rules = PutawayRule::query()
            ->when($request->filled('warehouse_id'), static fn ($query) => $query->where('warehouse_id', (string) $request->query('warehouse_id')))
            ->orderByRaw('CASE WHEN stock_item_id IS NOT NULL THEN 0 WHEN category_id IS NOT NULL THEN 1 ELSE 2 END')
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $rules->map(static fn (PutawayRule $rule): array => self::rule($rule))->all()]);
    }

    #[OA\Post(
        path: '/api/v1/inventory/putaway-rules',
        operationId: 'wmsStorePutawayRule',
        summary: 'Create a putaway rule',
        description: 'Manager decision (owner/admin). For an item, an item category or every item (neither given) of a warehouse: the target bin, zone or bin type, the strategy (fixed_location, consolidate = bins already holding the item first, empty_bin = empty bins first) and the priority (lower first).',
        tags: ['Inventory Putaway'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WmsPutawayRuleInput')),
        responses: [
            new OA\Response(response: 201, description: 'Rule created', content: new OA\JsonContent(ref: '#/components/schemas/WmsPutawayRule')),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function store(PutawayRuleRequest $request): JsonResponse
    {
        $this->authorize('inventory.manage');

        $rule = PutawayRule::create($request->validated() + ['priority' => 100, 'is_active' => true]);

        return response()->json(self::rule($rule->fresh() ?? $rule), Response::HTTP_CREATED);
    }

    #[OA\Put(
        path: '/api/v1/inventory/putaway-rules/{id}',
        operationId: 'wmsUpdatePutawayRule',
        summary: 'Update a putaway rule',
        description: 'Manager decision (owner/admin). Changes any field of the rule; a rule can be switched off with is_active=false.',
        tags: ['Inventory Putaway'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsRecordId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WmsPutawayRuleInput')),
        responses: [
            new OA\Response(response: 200, description: 'Rule updated', content: new OA\JsonContent(ref: '#/components/schemas/WmsPutawayRule')),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function update(string $id, PutawayRuleRequest $request): JsonResponse
    {
        $rule = PutawayRule::query()->findOrFail($id);
        $this->authorize('inventory.manage');

        $rule->update($request->validated());

        return response()->json(self::rule($rule->fresh() ?? $rule));
    }

    #[OA\Delete(
        path: '/api/v1/inventory/putaway-rules/{id}',
        operationId: 'wmsDeletePutawayRule',
        summary: 'Delete a putaway rule',
        description: 'Manager decision (owner/admin). The rule stops directing putaway.',
        tags: ['Inventory Putaway'],
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
        $rule = PutawayRule::query()->findOrFail($id);
        $this->authorize('inventory.manage');

        $rule->delete();

        return response()->noContent();
    }

    #[OA\Post(
        path: '/api/v1/inventory/putaway/suggest',
        operationId: 'wmsSuggestPutaway',
        summary: 'Suggest bins for incoming stock',
        description: 'Bins where the quantity of the item should be stored, best first: from the putaway rules (item, category, generic; by priority and strategy), keeping only unlocked storage/picking/bulk bins outside quarantine and transit with capacity for the quantity. Without a matching rule: bins already holding the item, then empty bins (source=fallback). Read-only: nothing moves.',
        tags: ['Inventory Putaway'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['warehouse_id', 'stock_item_id', 'quantity'], properties: [
            new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'quantity', type: 'number', example: 24),
            new OA\Property(property: 'limit', type: 'integer', example: 5, description: 'How many bins (default 5, max 50)'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Suggested bins', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WmsPutawaySuggestion'))])),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function suggest(PutawayRequest $request, PutawayService $putaway): JsonResponse
    {
        $warehouse = Warehouse::query()->findOrFail($request->string('warehouse_id')->value());
        $item = StockItem::query()->findOrFail($request->string('stock_item_id')->value());

        return response()->json(['data' => $putaway->suggest($warehouse, $item, Quantity::of((string) $request->input('quantity')), (int) $request->input('limit', 5))]);
    }

    #[OA\Post(
        path: '/api/v1/inventory/putaway',
        operationId: 'wmsExecutePutaway',
        summary: 'Put stock away',
        description: 'Moves stock from a receiving or staging bin to its storage bin (the given one, else the best suggestion) as a double-entry movement of type putaway. Refused (422) for stock in quarantine (released by the quality decision of its receipt), a destination that is locked, not storable, in another warehouse or without capacity, a bin frozen by a physical count, or missing stock.',
        tags: ['Inventory Putaway'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['source_bin_id', 'stock_item_id', 'quantity'], properties: [
            new OA\Property(property: 'source_bin_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'quantity', type: 'number', example: 24),
            new OA\Property(property: 'lot_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'destination_bin_id', type: 'string', format: 'uuid', nullable: true, description: 'Omit to use the best suggestion'),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'Stock put away', content: new OA\JsonContent(ref: '#/components/schemas/WmsMovement')),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function putAway(PutawayRequest $request, PutawayService $putaway): JsonResponse
    {
        $source = WarehouseBin::query()->with('rack.aisle.zone')->findOrFail($request->string('source_bin_id')->value());
        $item = StockItem::query()->findOrFail($request->string('stock_item_id')->value());
        $destination = $request->filled('destination_bin_id') ? WarehouseBin::query()->with('rack.aisle.zone')->findOrFail($request->string('destination_bin_id')->value()) : null;

        $movement = $putaway->putAway($source, $item, Quantity::of((string) $request->input('quantity')), $request->input('lot_id'), $destination, (string) auth()->id());

        return response()->json(new StockMovementResource($movement->load(['item', 'sourceBin', 'destinationBin'])), Response::HTTP_CREATED);
    }

    /**
     * @return array<string, mixed>
     */
    private static function rule(PutawayRule $rule): array
    {
        return [
            'id' => (string) $rule->id,
            'warehouse_id' => (string) $rule->warehouse_id,
            'stock_item_id' => $rule->stock_item_id,
            'category_id' => $rule->category_id,
            'target_zone_id' => $rule->target_zone_id,
            'target_bin_id' => $rule->target_bin_id,
            'bin_type' => $rule->bin_type,
            'strategy' => (string) $rule->strategy,
            'priority' => (int) $rule->priority,
            'is_active' => (bool) $rule->is_active,
        ];
    }
}
