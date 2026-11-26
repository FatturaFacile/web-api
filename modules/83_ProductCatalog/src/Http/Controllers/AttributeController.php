<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\ProductCatalog\Http\Controllers\Concerns\InteractsWithCatalog;
use Modules\ProductCatalog\Http\Requests\SaveAttributeRequest;
use Modules\ProductCatalog\Http\Resources\AttributeResource;
use Modules\ProductCatalog\Models\CatalogAttribute;
use Modules\ProductCatalog\Services\AttributeService;
use OpenApi\Attributes as OA;

/**
 * Attribute definitions (the axes of a variant matrix) with their values, SKU tokens and surcharges.
 */
final class AttributeController extends Controller
{
    use ApiResponse;
    use InteractsWithCatalog;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(private readonly AttributeService $attributes) {}

    #[OA\Get(path: '/api/v1/catalog/attributes', operationId: 'productCatalogAttributes', summary: 'List the attributes with their values', security: self::SECURITY, tags: ['Product Catalog'], responses: [new OA\Response(response: 200, description: 'Attributes')])]
    public function index(): JsonResponse
    {
        $this->authorizeCatalog();
        $attributes = CatalogAttribute::query()->with('values')->orderBy('sort_order')->orderBy('code')->get();

        return $this->successResponse(AttributeResource::collection($attributes), $this->message('listed'));
    }

    #[OA\Post(path: '/api/v1/catalog/attributes', operationId: 'productCatalogAttributeCreate', summary: 'Create an attribute with its values', security: self::SECURITY, tags: ['Product Catalog'], responses: [new OA\Response(response: 201, description: 'Attribute created')])]
    public function store(SaveAttributeRequest $request): JsonResponse
    {
        $this->authorizeCatalog();
        $attribute = $this->attributes->create($request->validated());

        return $this->successResponse(new AttributeResource($attribute), $this->message('saved'), 201);
    }

    #[OA\Put(path: '/api/v1/catalog/attributes/{id}', operationId: 'productCatalogAttributeUpdate', summary: 'Change an attribute and synchronise its values', security: self::SECURITY, tags: ['Product Catalog'], responses: [new OA\Response(response: 200, description: 'Attribute changed')])]
    public function update(SaveAttributeRequest $request, string $id): JsonResponse
    {
        $this->authorizeCatalog();
        $attribute = $this->attributes->update(CatalogAttribute::query()->findOrFail($id), $request->validated());

        return $this->successResponse(new AttributeResource($attribute), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/catalog/attributes/{id}', operationId: 'productCatalogAttributeDelete', summary: 'Delete an attribute that no matrix uses', security: self::SECURITY, tags: ['Product Catalog'], responses: [new OA\Response(response: 200, description: 'Attribute deleted')])]
    public function destroy(string $id): JsonResponse
    {
        $this->authorizeCatalog();
        $this->attributes->delete(CatalogAttribute::query()->findOrFail($id));

        return $this->successResponse(null, $this->message('deleted'));
    }
}
