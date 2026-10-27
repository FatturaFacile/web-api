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
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class PriceListController extends Controller
{
    use HandlesLookupCrud;

    public function __construct(private readonly PriceResolver $priceResolver) {}

    protected function modelClass(): string
    {
        return PriceList::class;
    }

    protected function label(): string
    {
        return __('api.labels.price_list');
    }

    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new CatalogResource(PriceList::with('items')->findOrFail($id)), __('catalog.messages.price_list_retrieved'));
    }

    public function store(StorePriceListRequest $request): JsonResponse
    {
        $list = $this->persist(new PriceList, $request->validated());

        return $this->successResponse(new CatalogResource($list), __('catalog.messages.price_list_created'), Response::HTTP_CREATED);
    }

    public function update(StorePriceListRequest $request, string $id): JsonResponse
    {
        $list = $this->persist(PriceList::findOrFail($id), $request->validated());

        return $this->successResponse(new CatalogResource($list), __('catalog.messages.price_list_updated'));
    }

    /**
     * Resolve the effective unit price for a product, contact and quantity.
     */
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
                ], $items));
            }

            return $list->load('items');
        });
    }
}
