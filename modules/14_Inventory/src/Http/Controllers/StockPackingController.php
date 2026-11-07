<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Http\Requests\PackPickListRequest;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockPackingList;
use Modules\Inventory\Models\StockPickList;
use Modules\Inventory\Services\PackagingCalculator;
use Modules\Inventory\Services\PackingListService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Inventory Packing', description: 'Packing lists with packages, cartons, pallets, net and gross weight')]
final class StockPackingController extends Controller
{
    #[OA\Post(
        path: '/api/v1/inventory/packing/calculate',
        summary: 'Calculate cartons, pallets and weight',
        description: 'Plans cartons, pallets, net and gross weight and volume from the packaging data of the items, without changing anything.',
        tags: ['Inventory Packing'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['items'],
                properties: [
                    new OA\Property(property: 'items', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'quantity', type: 'number', example: 250),
                    ])),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Packaging plan', content: new OA\JsonContent(ref: '#/components/schemas/InventoryPackagingPlan')),
            new OA\Response(response: 404, description: 'Item not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Calculate cartons, pallets and weight', tags: ['Inventory'])]
    public function calculate(Request $request, PackagingCalculator $calculator): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.stock_item_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        $lines = array_map(static fn (array $line): array => [
            'item' => StockItem::findOrFail($line['stock_item_id']),
            'quantity' => bcadd((string) $line['quantity'], '0', 4),
        ], $validated['items']);

        return response()->json($calculator->forLines($lines));
    }

    #[OA\Post(
        path: '/api/v1/inventory/pick-lists/{id}/packing-list',
        summary: 'Pack a pick list',
        description: 'Creates the packing list of what was picked: cartons and pallets from the item master, or the mixed `packages` given. A linked delivery note receives packages count and weights.',
        tags: ['Inventory Packing'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'delivery_note_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'packages', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'package_type', type: 'string', enum: ['box', 'carton', 'pallet']),
                        new OA\Property(property: 'tare_weight_kg', type: 'number'),
                        new OA\Property(property: 'items', type: 'array', items: new OA\Items(properties: [
                            new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'lot_id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'quantity', type: 'number'),
                        ])),
                    ])),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Packing list created', content: new OA\JsonContent(ref: '#/components/schemas/InventoryPackingList')),
            new OA\Response(response: 404, description: 'Pick list not found'),
            new OA\Response(response: 422, description: 'Nothing picked, already packed, or the packages do not match the picked goods'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Pack a pick list', tags: ['Inventory'])]
    public function store(string $id, PackPickListRequest $request, PackingListService $packing): JsonResponse
    {
        $packingList = $packing->pack(StockPickList::findOrFail($id), (string) auth()->id(), $request->input('delivery_note_id'), $request->validated('packages'));

        return response()->json($this->present($packingList), 201);
    }

    #[OA\Get(
        path: '/api/v1/inventory/packing-lists/{id}',
        summary: 'Get a packing list',
        tags: ['Inventory Packing'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Packing list', content: new OA\JsonContent(ref: '#/components/schemas/InventoryPackingList')),
            new OA\Response(response: 404, description: 'Packing list not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Get a packing list', tags: ['Inventory'])]
    public function show(string $id): JsonResponse
    {
        return response()->json($this->present(StockPackingList::with('packages.items')->findOrFail($id)));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(StockPackingList $packingList): array
    {
        return [
            'id' => $packingList->id,
            'packing_number' => $packingList->packing_number,
            'stock_pick_list_id' => $packingList->stock_pick_list_id,
            'delivery_note_id' => $packingList->delivery_note_id,
            'total_packages' => $packingList->total_packages,
            'cartons_count' => $packingList->cartons_count,
            'pallets_count' => $packingList->pallets_count,
            'net_weight_kg' => $packingList->net_weight_kg,
            'gross_weight_kg' => $packingList->total_weight_kg,
            'total_volume_m3' => $packingList->total_volume_m3,
            'packages' => $packingList->packages->map(static fn ($package): array => [
                'id' => $package->id,
                'package_number' => $package->package_number,
                'package_type' => $package->package_type,
                'parent_package_id' => $package->parent_package_id,
                'net_weight_kg' => $package->net_weight_kg,
                'tare_weight_kg' => $package->tare_weight_kg,
                'gross_weight_kg' => $package->gross_weight_kg,
                'items' => $package->items->map(static fn ($content): array => [
                    'stock_item_id' => $content->stock_item_id,
                    'lot_id' => $content->lot_id,
                    'quantity' => (string) $content->quantity,
                    'net_weight_kg' => $content->net_weight_kg,
                ])->all(),
            ])->all(),
        ];
    }
}
