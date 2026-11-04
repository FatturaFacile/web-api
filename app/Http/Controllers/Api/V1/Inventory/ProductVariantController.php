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
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

#[OA\Tag(name: 'Product Variants', description: 'Product variant management and matrix generation')]
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
    #[OA\Get(
        path: '/api/v1/inventory/products/{parentId}/variants',
        summary: 'List variants of a product',
        description: 'Get all product variants for a parent product with stock levels',
        tags: ['Product Variants'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'parentId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Variants listed successfully'),
            new OA\Response(response: 404, description: 'Parent product not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
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
    #[OA\Post(
        path: '/api/v1/inventory/products/{parentId}/variants',
        summary: 'Create product variant',
        description: 'Create a single product variant manually under a parent product',
        tags: ['Product Variants'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'parentId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['sku', 'attributes'],
                properties: [
                    new OA\Property(property: 'sku', type: 'string'),
                    new OA\Property(property: 'price', type: 'number'),
                    new OA\Property(property: 'barcode', type: 'string'),
                    new OA\Property(property: 'attributes', type: 'object'),
                    new OA\Property(property: 'is_active', type: 'boolean'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Variant created successfully'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
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
    #[OA\Post(
        path: '/api/v1/inventory/products/{parentId}/variants/generate',
        summary: 'Generate variant matrix',
        description: 'Generate product variants in bulk based on an attribute matrix',
        tags: ['Product Variants'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'parentId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['matrix'],
                properties: [
                    new OA\Property(property: 'matrix', type: 'object'),
                    new OA\Property(property: 'options', type: 'object'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Variants generated successfully'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
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

    #[OA\Get(
        path: '/api/v1/inventory/products/variants/{variantId}',
        summary: 'Get variant details',
        description: 'Retrieve details for a single product variant',
        tags: ['Product Variants'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'variantId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Variant details retrieved'),
            new OA\Response(response: 404, description: 'Variant not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function show(string $variantId): JsonResponse
    {
        $variant = $this->variant($variantId);

        return $this->successResponse(new ProductVariantResource($variant, $this->stock->quantityOf($variant->id)), __('catalog.variants.retrieved'));
    }

    /**
     * Update a variant. Sending `is_active: true` reactivates a variant switched off at the end of its series.
     */
    #[OA\Put(
        path: '/api/v1/inventory/products/variants/{variantId}',
        summary: 'Update variant',
        description: 'Update attributes or details of a product variant',
        tags: ['Product Variants'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'variantId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'sku', type: 'string'),
                    new OA\Property(property: 'price', type: 'number'),
                    new OA\Property(property: 'barcode', type: 'string'),
                    new OA\Property(property: 'attributes', type: 'object'),
                    new OA\Property(property: 'is_active', type: 'boolean'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Variant updated successfully'),
            new OA\Response(response: 404, description: 'Variant not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
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
    #[OA\Delete(
        path: '/api/v1/inventory/products/variants/{variantId}',
        summary: 'Delete variant',
        description: 'Delete an unreferenced product variant',
        tags: ['Product Variants'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'variantId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Variant deleted successfully'),
            new OA\Response(response: 404, description: 'Variant not found'),
            new OA\Response(response: 422, description: 'Cannot delete variant with existing references'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function destroy(ProductReferences $references, string $variantId): JsonResponse
    {
        $variant = $this->variant($variantId);

        if ($references->exist($variant->id)) {
            throw ValidationException::withMessages(['variant' => __('inventory.variant_has_references')]);
        }

        $variant->getConnection()->transaction(function () use ($variant): void {
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
