<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\HandlesLookupCrud;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ResolvePriceRequest;
use App\Http\Requests\Catalog\StorePriceListRequest;
use App\Http\Resources\CatalogResource;
use App\Models\Contact;
use App\Models\PriceList;
use App\Models\Product;
use App\Services\Catalog\PriceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class PriceListController extends Controller
{
    use HandlesLookupCrud {
        index as lookupIndex;
        destroy as lookupDestroy;
    }

    public function __construct(private readonly PriceResolver $priceResolver) {}

    protected function modelClass(): string
    {
        return PriceList::class;
    }

    protected function label(): string
    {
        return __('api.labels.price_list');
    }

    #[OA\Get(
        path: '/api/v1/catalog/price-lists',
        operationId: 'getPriceLists',
        description: 'List tenant price lists and tier pricing rules.',
        summary: 'List price lists',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Catalog', 'Pricing'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Price lists retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        return $this->lookupIndex($request);
    }

    #[OA\Get(
        path: '/api/v1/catalog/price-lists/{id}',
        operationId: 'getPriceList',
        description: 'Get details of a specific price list with line items.',
        summary: 'Get price list',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Catalog', 'Pricing'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Price list retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Price list not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new CatalogResource(PriceList::with('items')->findOrFail($id)), __('catalog.messages.price_list_retrieved'));
    }

    #[OA\Post(
        path: '/api/v1/catalog/price-lists',
        operationId: 'storePriceList',
        description: 'Create a new catalog price list with tier item prices.',
        summary: 'Create price list',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Catalog', 'Pricing'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'currency'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Wholesale Tier A'),
                    new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
                    new OA\Property(property: 'is_default', type: 'boolean', default: false),
                    new OA\Property(property: 'parent_price_list_id', type: 'string', format: 'uuid', nullable: true, description: 'Products missing here are priced from this list'),
                    new OA\Property(property: 'price_adjustment_percent', type: 'number', nullable: true, description: 'Applied to prices inherited from the parent'),
                    new OA\Property(property: 'items', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'price', type: 'number'),
                        new OA\Property(property: 'min_quantity', type: 'number'),
                        new OA\Property(property: 'valid_from', type: 'string', format: 'date', nullable: true),
                        new OA\Property(property: 'valid_to', type: 'string', format: 'date', nullable: true),
                    ])),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Price list created successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StorePriceListRequest $request): JsonResponse
    {
        $list = $this->persist(new PriceList, $request->validated());

        return $this->successResponse(new CatalogResource($list), __('catalog.messages.price_list_created'), Response::HTTP_CREATED);
    }

    #[OA\Put(
        path: '/api/v1/catalog/price-lists/{id}',
        operationId: 'updatePriceList',
        description: 'Update price list header and line items.',
        summary: 'Update price list',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Catalog', 'Pricing'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'currency', type: 'string'),
                    new OA\Property(property: 'is_default', type: 'boolean'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Price list updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Price list not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(StorePriceListRequest $request, string $id): JsonResponse
    {
        $list = $this->persist(PriceList::findOrFail($id), $request->validated());

        return $this->successResponse(new CatalogResource($list), __('catalog.messages.price_list_updated'));
    }

    #[OA\Delete(
        path: '/api/v1/catalog/price-lists/{id}',
        operationId: 'deletePriceList',
        description: 'Delete a price list.',
        summary: 'Delete price list',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Catalog', 'Pricing'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Price list deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Price list not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        return $this->lookupDestroy($id);
    }

    /**
     * Resolve the effective unit price for a product, contact and quantity.
     */
    #[OA\Get(
        path: '/api/v1/catalog/price-lists/resolve-price',
        operationId: 'resolveProductPrice',
        description: 'Resolve the effective unit price for a product, contact and quantity considering customer custom lists and tier discounts.',
        summary: 'Resolve product price',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Catalog', 'Pricing'],
        parameters: [
            new OA\Parameter(name: 'product_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'contact_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'quantity', in: 'query', required: false, schema: new OA\Schema(type: 'number', default: 1)),
            new OA\Parameter(name: 'date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Price resolved successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function resolve(ResolvePriceRequest $request): JsonResponse
    {
        $data = $request->validated();

        $result = $this->priceResolver->resolve(
            Product::findOrFail($data['product_id']),
            isset($data['contact_id']) ? Contact::findOrFail($data['contact_id']) : null,
            (float) ($data['quantity'] ?? 1),
            isset($data['date']) ? Carbon::parse($data['date']) : null,
        );

        return $this->successResponse($result, __('catalog.messages.price_resolved'));
    }

    /**
     * Save the list, keep a single default list per tenant and replace items when provided.
     *
     * @param  array<string, mixed>  $data
     */
    private function persist(PriceList $list, array $data): PriceList
    {
        return PriceList::resolveConnection()->transaction(function () use ($list, $data): PriceList {
            $items = $data['items'] ?? null;
            unset($data['items']);

            $list->fill($data)->save();

            if (! empty($data['is_default'])) {
                PriceList::where('id', '!=', $list->id)->where('is_default', true)->update(['is_default' => false]);
            }

            if ($items !== null) {
                $list->items()->delete();
                $list->items()->createMany(array_map(fn (array $item): array => [
                    'product_id' => $item['product_id'],
                    'price' => $item['price'],
                    'min_quantity' => $item['min_quantity'] ?? 1,
                    'valid_from' => $item['valid_from'] ?? null,
                    'valid_to' => $item['valid_to'] ?? null,
                ], $items));
            }

            return $list->load('items');
        });
    }
}
