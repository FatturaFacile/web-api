<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\HandlesLookupCrud;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductCategoryRequest;
use App\Models\ProductCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ProductCategoryController extends Controller
{
    use HandlesLookupCrud {
        index as lookupIndex;
        show as lookupShow;
        destroy as lookupDestroy;
    }

    protected function modelClass(): string
    {
        return ProductCategory::class;
    }

    protected function label(): string
    {
        return __('api.labels.product_category');
    }

    #[OA\Get(
        path: '/api/v1/catalog/categories',
        operationId: 'getProductCategories',
        description: 'List product categories in catalog.',
        summary: 'List product categories',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Categories retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        return $this->lookupIndex($request);
    }

    #[OA\Post(
        path: '/api/v1/catalog/categories',
        operationId: 'storeProductCategory',
        description: 'Create a new product category in the catalog.',
        summary: 'Create product category',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Catalog'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Hardware'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Category created successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreProductCategoryRequest $request): JsonResponse
    {
        return $this->createRecord($request->validated());
    }

    #[OA\Get(
        path: '/api/v1/catalog/categories/{id}',
        operationId: 'getProductCategory',
        description: 'Get details of a specific product category.',
        summary: 'Get product category',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Category retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Category not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->lookupShow($id);
    }

    #[OA\Put(
        path: '/api/v1/catalog/categories/{id}',
        operationId: 'updateProductCategory',
        description: 'Update product category details.',
        summary: 'Update product category',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Category updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Category not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(StoreProductCategoryRequest $request, string $id): JsonResponse
    {
        return $this->updateRecord($id, $request->validated());
    }

    #[OA\Delete(
        path: '/api/v1/catalog/categories/{id}',
        operationId: 'deleteProductCategory',
        description: 'Delete a product category from the catalog.',
        summary: 'Delete product category',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Category deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Category not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        return $this->lookupDestroy($id);
    }
}
