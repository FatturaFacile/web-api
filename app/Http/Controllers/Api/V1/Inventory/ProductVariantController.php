<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Actions\Inventory\GenerateProductVariantsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\CreateProductVariantRequest;
use App\Http\Requests\Inventory\GenerateVariantsMatrixRequest;
use App\Http\Requests\Inventory\UpdateProductVariantRequest;
use App\Http\Resources\ProductVariantResource;
use App\Http\Responses\ApiResponse;
use App\Models\EcommerceMapping;
use App\Models\Product;
use App\Services\Inventory\ProductReferences;
use App\Services\Inventory\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ProductVariantController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly StockService $stock,
        private readonly GenerateProductVariantsAction $generator,
    ) {}

    /**
     * The variants of a parent product with their calculated stock.
     */
    public function index(string $parentId): JsonResponse
    {
        $parent = $this->parent($parentId);
        $levels = $this->stock->levels()->pluck('quantity', 'product_id');

        $variants = $parent->variants()->orderBy('sku')->get()
            ->map(fn (Product $variant): ProductVariantResource => new ProductVariantResource($variant, (float) ($levels[$variant->id] ?? 0)))
            ->all();

        return $this->successResponse($variants, __('catalog.variants.listed'));
    }

    /**
     * Create one variant by hand.
     */
    public function store(CreateProductVariantRequest $request, string $parentId): JsonResponse
    {
        $parent = $this->parent($parentId);
        $variant = $this->generator->createVariant(
            $parent,
            $request->optionAttributes(),
            ['discontinue_on_zero_stock' => $request->discontinueFlag() ?? false],
            array_filter([
                'sku' => $request->input('sku'),
                'price' => $request->input('price'),
                'barcode' => $request->input('barcode'),
                'min_stock' => $request->input('min_stock'),
                'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
            ], fn (mixed $value): bool => $value !== null),
        );

        return $this->successResponse(new ProductVariantResource($variant, 0.0), __('catalog.variants.created'), Response::HTTP_CREATED);
    }

    /**
     * Generate every missing combination of an attribute matrix; combinations that already exist are skipped.
     */
    public function generate(GenerateVariantsMatrixRequest $request, string $parentId): JsonResponse
    {
        $result = $this->generator->execute($this->parent($parentId), $request->matrix(), $request->options());

        return $this->successResponse(
            [
                'created' => $result['created']->map(fn (Product $variant): ProductVariantResource => new ProductVariantResource($variant, 0.0))->values()->all(),
                'created_count' => $result['created']->count(),
                'skipped_count' => $result['skipped'],
            ],
            __('catalog.variants.generated'),
            Response::HTTP_CREATED
        );
    }

    public function show(string $variantId): JsonResponse
    {
        $variant = $this->variant($variantId);

        return $this->successResponse(new ProductVariantResource($variant, $this->stock->quantityOf($variant->id)), __('catalog.variants.retrieved'));
    }

    /**
     * Update a variant. Sending `is_active: true` reactivates a variant switched off at the end of its series.
     */
    public function update(UpdateProductVariantRequest $request, string $variantId): JsonResponse
    {
        $variant = $this->variant($variantId);
        $changes = [];

        foreach (['sku', 'barcode', 'is_active', 'min_stock'] as $field) {
            if ($request->has($field)) {
                $changes[$field] = $field === 'is_active' ? $request->boolean($field) : $request->input($field);
            }
        }

        if ($request->has('price')) {
            $changes['sale_price'] = round((float) $request->input('price'), 4);
        }

        $flag = $request->discontinueFlag();

        if ($request->has('attributes') || $flag !== null) {
            $options = $request->has('attributes') ? $request->optionAttributes() : $variant->optionAttributes();
            $changes['attributes'] = $options + [Product::DISCONTINUE_FLAG => $flag ?? $variant->discontinuesOnZeroStock()];

            if ($request->has('attributes')) {
                $changes['name'] = $this->generator->nameFor($this->parent((string) $variant->parent_id), $options);
            }
        }

        $variant->update($changes);

        return $this->successResponse(new ProductVariantResource($variant->refresh(), $this->stock->quantityOf($variant->id)), __('catalog.variants.updated'));
    }

    /**
     * Delete a variant that nothing refers to: stock movements and document lines block the deletion.
     */
    public function destroy(ProductReferences $references, string $variantId): JsonResponse
    {
        $variant = $this->variant($variantId);

        if ($references->exist($variant->id)) {
            throw ValidationException::withMessages(['variant' => __('inventory.variant_has_references')]);
        }

        DB::transaction(function () use ($variant): void {
            EcommerceMapping::query()->where('local_id', $variant->id)->delete();
            $variant->forceDelete();
        });

        return $this->successResponse(null, __('catalog.variants.deleted'));
    }

    private function parent(string $parentId): Product
    {
        return Product::withoutVariants()->findOrFail($parentId);
    }

    private function variant(string $variantId): Product
    {
        return Product::variantsOnly()->findOrFail($variantId);
    }
}
