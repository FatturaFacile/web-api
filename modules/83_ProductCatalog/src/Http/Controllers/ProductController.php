<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\ProductCatalog\Http\Controllers\Concerns\InteractsWithCatalog;
use Modules\ProductCatalog\Http\Requests\ListProductsRequest;
use Modules\ProductCatalog\Http\Requests\SaveProductRequest;
use Modules\ProductCatalog\Http\Resources\ProductResource;
use Modules\ProductCatalog\Services\CatalogProductService;
use OpenApi\Attributes as OA;

/**
 * Catalog products: storable, consumable, service and digital items, simple or parent of a variant matrix, kit or not.
 */
final class ProductController extends Controller
{
    use ApiResponse;
    use InteractsWithCatalog;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(private readonly CatalogProductService $products) {}

    #[OA\Get(path: '/api/v1/catalog/products', operationId: 'productCatalogList', summary: 'List the catalog products (parents and simple products, or variants)', security: self::SECURITY, tags: ['Product Catalog'], responses: [new OA\Response(response: 200, description: 'Products, paginated')])]
    public function index(ListProductsRequest $request): JsonResponse
    {
        $this->authorizeCatalog();

        return $this->paged($this->products->paginate($request->validated()), ProductResource::class);
    }

    #[OA\Post(path: '/api/v1/catalog/products', operationId: 'productCatalogCreate', summary: 'Create a catalog product', security: self::SECURITY, tags: ['Product Catalog'], responses: [new OA\Response(response: 201, description: 'Product created')])]
    public function store(SaveProductRequest $request): JsonResponse
    {
        $this->authorizeCatalog();
        $product = $this->products->create($request->validated());

        return $this->successResponse(new ProductResource($product), $this->message('saved'), 201);
    }

    #[OA\Get(path: '/api/v1/catalog/products/{id}', operationId: 'productCatalogShow', summary: 'A catalog product with its axes, variants, packagings and kit components', security: self::SECURITY, tags: ['Product Catalog'], responses: [new OA\Response(response: 200, description: 'Product detail')])]
    public function show(string $id): JsonResponse
    {
        $this->authorizeCatalog();

        return $this->successResponse(ProductResource::detail($this->product($id), request()), $this->message('loaded'));
    }

    #[OA\Put(path: '/api/v1/catalog/products/{id}', operationId: 'productCatalogUpdate', summary: 'Change a catalog product', security: self::SECURITY, tags: ['Product Catalog'], responses: [new OA\Response(response: 200, description: 'Product changed')])]
    public function update(SaveProductRequest $request, string $id): JsonResponse
    {
        $this->authorizeCatalog();
        $product = $this->products->update($this->product($id), $request->validated());

        return $this->successResponse(new ProductResource($product), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/catalog/products/{id}', operationId: 'productCatalogDelete', summary: 'Delete a catalog product and its variants', security: self::SECURITY, tags: ['Product Catalog'], responses: [new OA\Response(response: 200, description: 'Product deleted')])]
    public function destroy(string $id): JsonResponse
    {
        $this->authorizeCatalog();
        $this->products->delete($this->product($id));

        return $this->successResponse(null, $this->message('deleted'));
    }
}
