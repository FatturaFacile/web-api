<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\AssetManagement\Http\Resources\AssetCategoryResource;
use Modules\AssetManagement\Http\Resources\AssetLocationResource;
use Modules\AssetManagement\Models\AssetCategory;
use Modules\AssetManagement\Models\AssetClass;
use Modules\AssetManagement\Models\AssetLocation;
use OpenApi\Attributes as OA;

final class AssetCatalogController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Returns the asset categories of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/eam/categories',
        operationId: 'getCategoriesEam',
        description: 'Returns the asset categories of the active tenant.',
        summary: 'List asset categories',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Categories'],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssetManagementAssetCategoryResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function categories(): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $categories = AssetCategory::where('tenant_id', $tenant->id)->get();

        return response()->json(['data' => AssetCategoryResource::collection($categories)]);
    }

    /**
     * Creates an asset category in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/eam/categories',
        operationId: 'storeCategoryEamCategories',
        description: 'Creates an asset category in the active tenant.',
        summary: 'Create asset category',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Categories'],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'parent_id'), new OA\Property(property: 'code'), new OA\Property(property: 'name'), new OA\Property(property: 'asset_type', type: 'string', default: 'tangible'), new OA\Property(property: 'depreciation_method', type: 'string', default: 'straight_line'), new OA\Property(property: 'useful_life_years', type: 'integer', default: 5), new OA\Property(property: 'statutory_rate_percent', type: 'number', default: 20), new OA\Property(property: 'salvage_value_percent', type: 'number', default: 0), new OA\Property(property: 'is_depreciable', type: 'boolean', default: true)])),
        responses: [
            new OA\Response(response: 201, description: 'Category created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementAssetCategoryResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function storeCategory(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();

        $category = AssetCategory::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->id,
            'parent_id' => $request->input('parent_id'),
            'code' => $request->input('code'),
            'name' => $request->input('name'),
            'asset_type' => $request->input('asset_type', 'tangible'),
            'depreciation_method' => $request->input('depreciation_method', 'straight_line'),
            'useful_life_years' => (int) $request->input('useful_life_years', 5),
            'useful_life_months' => ((int) $request->input('useful_life_years', 5)) * 12,
            'statutory_rate_percent' => (float) $request->input('statutory_rate_percent', 20.0),
            'salvage_value_percent' => (float) $request->input('salvage_value_percent', 0.0),
            'is_depreciable' => (bool) $request->input('is_depreciable', true),
            'is_active' => true,
        ]);

        return response()->json(['data' => new AssetCategoryResource($category)], 201);
    }

    /**
     * Returns the asset classes of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/eam/classes',
        operationId: 'getClassesEam',
        description: 'Returns the asset classes of the active tenant.',
        summary: 'List asset classes',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Classes'],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssetManagementAssetClassModel'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function classes(): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $classes = AssetClass::where('tenant_id', $tenant->id)->get();

        return response()->json(['data' => $classes]);
    }

    /**
     * Returns the asset locations of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/eam/locations',
        operationId: 'getLocationsEam',
        description: 'Returns the asset locations of the active tenant.',
        summary: 'List asset locations',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Locations'],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssetManagementAssetLocationResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function locations(): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $locations = AssetLocation::where('tenant_id', $tenant->id)->get();

        return response()->json(['data' => AssetLocationResource::collection($locations)]);
    }

    /**
     * Creates an asset location in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/eam/locations',
        operationId: 'storeLocationEamLocations',
        description: 'Creates an asset location in the active tenant.',
        summary: 'Create asset location',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Locations'],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'parent_id'), new OA\Property(property: 'code'), new OA\Property(property: 'name'), new OA\Property(property: 'location_type', type: 'string', default: 'facility'), new OA\Property(property: 'address'), new OA\Property(property: 'city'), new OA\Property(property: 'country', type: 'string', default: 'IT')])),
        responses: [
            new OA\Response(response: 201, description: 'Location created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementAssetLocationResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function storeLocation(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();

        $location = AssetLocation::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->id,
            'parent_id' => $request->input('parent_id'),
            'code' => $request->input('code'),
            'name' => $request->input('name'),
            'location_type' => $request->input('location_type', 'facility'),
            'address' => $request->input('address'),
            'city' => $request->input('city'),
            'country' => $request->input('country', 'IT'),
            'is_active' => true,
        ]);

        return response()->json(['data' => new AssetLocationResource($location)], 201);
    }
}
