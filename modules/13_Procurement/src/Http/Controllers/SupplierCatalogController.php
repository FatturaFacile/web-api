<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Procurement\Http\Controllers\Concerns\ProcurementControllerHelpers;
use Modules\Procurement\Http\Requests\StoreSupplierCatalogRequest;
use Modules\Procurement\Http\Requests\StoreSupplierPriceRequest;
use Modules\Procurement\Http\Resources\SupplierCatalogResource;
use Modules\Procurement\Models\SupplierCatalog;
use Modules\Procurement\Services\SupplierPriceService;
use OpenApi\Attributes as OA;

/**
 * Supplier catalogue and price lists: catalogue entries per supplier (part number, internal SKU, lead time, minimum
 * quantity), prices by quantity break with validity, price lookup and the offers of every supplier for an item.
 */
#[OA\Tag(name: 'Procurement Catalogue', description: 'Supplier catalogue entries, price lists with validity and offers')]
final class SupplierCatalogController extends Controller
{
    use ProcurementControllerHelpers;

    public function __construct(private readonly SupplierPriceService $prices) {}

    /**
     * Paginated catalogue entries.
     */
    #[OA\Get(
        path: '/api/v1/procurement/catalogs',
        operationId: 'procurementCatalogsIndex',
        description: 'Catalogue entries of the company, optionally of one supplier or for one internal SKU, with their prices.',
        summary: 'List supplier catalogue entries',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement Catalogue'],
        parameters: [
            new OA\Parameter(name: 'supplier_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'internal_sku', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(ref: '#/components/parameters/ProcPage'),
            new OA\Parameter(ref: '#/components/parameters/ProcPerPage'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Catalogue entries', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProcurementCatalogResource'))])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = SupplierCatalog::query()->with('priceLists')->orderBy('name');

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', (string) $request->query('supplier_id'));
        }

        if ($request->filled('internal_sku')) {
            $query->where('internal_sku', (string) $request->query('internal_sku'));
        }

        return SupplierCatalogResource::collection($query->paginate($this->perPage($request)));
    }

    /**
     * Creates a catalogue entry.
     */
    #[OA\Post(
        path: '/api/v1/procurement/catalogs',
        operationId: 'procurementCatalogsStore',
        description: 'Adds an item to the catalogue of a supplier. The internal SKU links it to the stock item of the inventory, so receipts book stock.',
        summary: 'Create supplier catalogue entry',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['supplier_id', 'supplier_part_number', 'name'], properties: [
            new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'supplier_part_number', type: 'string'),
            new OA\Property(property: 'internal_sku', type: 'string', nullable: true),
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'uom', type: 'string'),
            new OA\Property(property: 'lead_time_days', type: 'integer'),
            new OA\Property(property: 'minimum_order_quantity', type: 'string'),
        ])),
        tags: ['Procurement Catalogue'],
        responses: [
            new OA\Response(response: 201, description: 'Entry created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProcurementCatalogResource')])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function store(StoreSupplierCatalogRequest $request): JsonResponse
    {
        $catalog = SupplierCatalog::create([...$request->validated(), 'tenant_id' => $this->tenantId()]);

        return (new SupplierCatalogResource($catalog->load('priceLists')))->response()->setStatusCode(201);
    }

    /**
     * One catalogue entry with its prices.
     */
    #[OA\Get(
        path: '/api/v1/procurement/catalogs/{id}',
        operationId: 'procurementCatalogsShow',
        description: 'A catalogue entry with every price of its list.',
        summary: 'Show supplier catalogue entry',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement Catalogue'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Entry', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProcurementCatalogResource')])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcNotFound', response: 404),
        ]
    )]
    public function show(string $id): SupplierCatalogResource
    {
        return new SupplierCatalogResource(SupplierCatalog::query()->with('priceLists')->findOrFail($id));
    }

    /**
     * Updates a catalogue entry.
     */
    #[OA\Put(
        path: '/api/v1/procurement/catalogs/{id}',
        operationId: 'procurementCatalogsUpdate',
        description: 'Updates the entry; the supplier cannot change.',
        summary: 'Update supplier catalogue entry',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProcurementCatalogResource')),
        tags: ['Procurement Catalogue'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Entry updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProcurementCatalogResource')])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ProcNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function update(StoreSupplierCatalogRequest $request, string $id): SupplierCatalogResource
    {
        $catalog = SupplierCatalog::query()->findOrFail($id);
        $catalog->update($request->validated());

        return new SupplierCatalogResource($catalog->load('priceLists'));
    }

    /**
     * Adds a price to the list of the entry.
     */
    #[OA\Post(
        path: '/api/v1/procurement/catalogs/{id}/prices',
        operationId: 'procurementCatalogsStorePrice',
        description: 'Adds a quantity break with its validity. Two prices of the same break and currency cannot be valid on the same day.',
        summary: 'Add supplier price',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProcurementCatalogPrice')),
        tags: ['Procurement Catalogue'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcRecordId')],
        responses: [
            new OA\Response(response: 201, description: 'Price added', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProcurementCatalogPrice')])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ProcNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function storePrice(StoreSupplierPriceRequest $request, string $id): JsonResponse
    {
        /** @var array{currency?: ?string, min_quantity?: string|null, unit_price: string, valid_from: string, valid_to?: ?string} $data */
        $data = $request->validated();
        $price = $this->prices->addPrice(SupplierCatalog::query()->findOrFail($id), $data);

        return response()->json(['data' => SupplierCatalogResource::price($price)], 201);
    }

    /**
     * The applicable price of the entry for a quantity on a date.
     */
    #[OA\Get(
        path: '/api/v1/procurement/catalogs/{id}/price',
        operationId: 'procurementCatalogsPrice',
        description: 'The price of the highest quantity break not above the quantity among the prices valid on the date (default today); null when none applies.',
        summary: 'Look up supplier price',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement Catalogue'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/ProcRecordId'),
            new OA\Parameter(name: 'quantity', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: '10')),
            new OA\Parameter(name: 'date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Applicable price', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProcurementCatalogPrice', nullable: true)])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function price(Request $request, string $id): JsonResponse
    {
        $query = $request->validate(['quantity' => ['nullable', 'decimal:0,4', 'gt:0'], 'date' => ['nullable', 'date_format:Y-m-d']]);
        $price = $this->prices->priceFor(SupplierCatalog::query()->findOrFail($id), (string) ($query['quantity'] ?? '1'), (string) ($query['date'] ?? now()->toDateString()));

        return response()->json(['data' => $price !== null ? SupplierCatalogResource::price($price) : null]);
    }

    /**
     * Offers of every supplier for an internal SKU.
     */
    #[OA\Get(
        path: '/api/v1/procurement/offers',
        operationId: 'procurementOffers',
        description: 'Valid offers of every active catalogue entry with the internal SKU for the quantity, cheapest first.',
        summary: 'Compare supplier offers for an item',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement Catalogue'],
        parameters: [
            new OA\Parameter(name: 'sku', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'quantity', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Offers', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function offers(Request $request): JsonResponse
    {
        $query = $request->validate(['sku' => ['required', 'string', 'max:100'], 'quantity' => ['nullable', 'decimal:0,4', 'gt:0']]);

        return response()->json(['data' => $this->prices->offersForSku((string) $query['sku'], (string) ($query['quantity'] ?? '1'), now()->toDateString())->all()]);
    }
}
