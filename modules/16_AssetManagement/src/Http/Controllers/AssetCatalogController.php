<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Modules\AssetManagement\Http\Requests\StoreAssetCategoryRequest;
use Modules\AssetManagement\Http\Requests\StoreAssetClassRequest;
use Modules\AssetManagement\Http\Requests\StoreAssetLocationRequest;
use Modules\AssetManagement\Http\Resources\AssetCategoryResource;
use Modules\AssetManagement\Http\Resources\AssetClassResource;
use Modules\AssetManagement\Http\Resources\AssetLocationResource;
use Modules\AssetManagement\Models\AssetCategory;
use Modules\AssetManagement\Models\AssetClass;
use Modules\AssetManagement\Models\AssetLocation;
use OpenApi\Attributes as OA;

/**
 * Master data of the asset module: categories (depreciation presets, fiscal parameters configured by the tenant and
 * ledger accounts), operational classes and locations.
 */
final class AssetCatalogController extends Controller
{
    /** Category attributes settable through the API. */
    private const array CategoryFields = [
        'parent_id', 'code', 'name', 'asset_type', 'depreciation_method', 'useful_life_years', 'statutory_rate_percent', 'salvage_value_percent',
        'pro_rata_convention', 'declining_balance_factor', 'fiscal_depreciation_method', 'fiscal_rate_percent', 'fiscal_useful_life_years',
        'fiscal_pro_rata_convention', 'rate_reference', 'gl_asset_account_code', 'gl_accumulated_depreciation_code', 'gl_depreciation_expense_code',
        'gl_impairment_account_code', 'gl_revaluation_reserve_code', 'gl_disposal_gain_loss_code', 'gl_disposal_loss_code',
        'gl_disposal_proceeds_code', 'gl_clearing_account_code', 'is_depreciable', 'is_active',
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLedgerInterface $auditLedger
    ) {}

    /**
     * Asset categories of the active company.
     */
    #[OA\Get(
        path: '/api/v1/eam/categories',
        operationId: 'getCategoriesEamCategories',
        description: 'Asset categories of the active company (at most 500), ordered by code.',
        summary: 'List asset categories',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Catalogs'],
        responses: [
            new OA\Response(response: 200, description: 'Categories', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssetManagementAssetCategoryResource'))])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
        ]
    )]
    public function categories(): AnonymousResourceCollection
    {
        return AssetCategoryResource::collection(AssetCategory::where('tenant_id', $this->tenantContext->getTenantId())->orderBy('code')->limit(500)->get());
    }

    /**
     * Creates an asset category.
     */
    #[OA\Post(
        path: '/api/v1/eam/categories',
        operationId: 'storeCategoryEamCategories',
        description: 'Creates an asset category. Fiscal depreciation rates (for example the coefficients of the Italian DM 31/12/1988 or the Swiss rates) are not shipped: enter them with their source in rate_reference.',
        summary: 'Create asset category',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Catalogs'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementStoreAssetCategoryRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Category created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementAssetCategoryResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function storeCategory(StoreAssetCategoryRequest $request): JsonResponse
    {
        $data = $request->safe()->only(self::CategoryFields);
        $lifeYears = (int) ($data['useful_life_years'] ?? 5);

        $category = AssetCategory::create([
            ...$data,
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenantContext->getTenantId(),
            'asset_type' => $data['asset_type'] ?? 'tangible',
            'depreciation_method' => $data['depreciation_method'] ?? 'straight_line',
            'useful_life_years' => $lifeYears,
            'useful_life_months' => $lifeYears * 12,
            'statutory_rate_percent' => $data['statutory_rate_percent'] ?? 0,
            'salvage_value_percent' => $data['salvage_value_percent'] ?? 0,
            'pro_rata_convention' => $data['pro_rata_convention'] ?? 'monthly',
            'is_depreciable' => $data['is_depreciable'] ?? true,
            'is_active' => $data['is_active'] ?? true,
        ]);

        $this->auditLedger->record('ASSET_CATEGORY_CREATED', $category, null, $category->toArray(), 'MODULE_16_EAM');

        return (new AssetCategoryResource($category->fresh()))->response()->setStatusCode(201);
    }

    /**
     * One asset category.
     */
    #[OA\Get(
        path: '/api/v1/eam/categories/{id}',
        operationId: 'showEamCategory',
        description: 'Returns an asset category of the active company.',
        summary: 'Get asset category',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Catalogs'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamCategoryId')],
        responses: [
            new OA\Response(response: 200, description: 'Category', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementAssetCategoryResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
        ]
    )]
    public function showCategory(string $id): AssetCategoryResource
    {
        return new AssetCategoryResource(AssetCategory::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id));
    }

    /**
     * Updates an asset category; existing books keep their parameters.
     */
    #[OA\Put(
        path: '/api/v1/eam/categories/{id}',
        operationId: 'updateEamCategory',
        description: 'Updates an asset category. Assets and books already capitalized keep their parameters; new assets and newly opened books take the new ones.',
        summary: 'Update asset category',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Catalogs'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamCategoryId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementStoreAssetCategoryRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Category updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementAssetCategoryResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function updateCategory(StoreAssetCategoryRequest $request, string $id): AssetCategoryResource
    {
        $category = AssetCategory::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);
        $before = $category->toArray();
        $data = $request->safe()->only(self::CategoryFields);

        if (($data['parent_id'] ?? null) === (string) $category->id) {
            abort(422, 'A category cannot be its own parent.');
        }
        if (isset($data['useful_life_years'])) {
            $data['useful_life_months'] = (int) $data['useful_life_years'] * 12;
        }

        $category->update($data);
        $this->auditLedger->record('ASSET_CATEGORY_UPDATED', $category, $before, $category->toArray(), 'MODULE_16_EAM');

        return new AssetCategoryResource($category->fresh());
    }

    /**
     * Operational asset classes of the active company.
     */
    #[OA\Get(
        path: '/api/v1/eam/classes',
        operationId: 'getClassesEamClasses',
        description: 'Operational asset classes of the active company (at most 500).',
        summary: 'List asset classes',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Catalogs'],
        responses: [
            new OA\Response(response: 200, description: 'Classes', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssetManagementAssetClassResource'))])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
        ]
    )]
    public function classes(): AnonymousResourceCollection
    {
        return AssetClassResource::collection(AssetClass::where('tenant_id', $this->tenantContext->getTenantId())->orderBy('code')->limit(500)->get());
    }

    /**
     * Creates an operational asset class.
     */
    #[OA\Post(
        path: '/api/v1/eam/classes',
        operationId: 'storeEamClass',
        description: 'Creates an operational asset class (criticality, default maintenance interval).',
        summary: 'Create asset class',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Catalogs'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementStoreAssetClassRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Class created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementAssetClassResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function storeClass(StoreAssetClassRequest $request): JsonResponse
    {
        $class = AssetClass::create([
            ...$request->validated(),
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenantContext->getTenantId(),
            'is_active' => true,
        ]);

        return (new AssetClassResource($class->fresh()))->response()->setStatusCode(201);
    }

    /**
     * Asset locations of the active company.
     */
    #[OA\Get(
        path: '/api/v1/eam/locations',
        operationId: 'getLocationsEamLocations',
        description: 'Asset locations of the active company (at most 1000), ordered by code.',
        summary: 'List asset locations',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Catalogs'],
        responses: [
            new OA\Response(response: 200, description: 'Locations', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssetManagementAssetLocationResource'))])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
        ]
    )]
    public function locations(): AnonymousResourceCollection
    {
        return AssetLocationResource::collection(AssetLocation::where('tenant_id', $this->tenantContext->getTenantId())->orderBy('code')->limit(1000)->get());
    }

    /**
     * Creates an asset location.
     */
    #[OA\Post(
        path: '/api/v1/eam/locations',
        operationId: 'storeLocationEamLocations',
        description: 'Creates an asset location (site, building, room, yard).',
        summary: 'Create asset location',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Catalogs'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementStoreAssetLocationRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Location created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementAssetLocationResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function storeLocation(StoreAssetLocationRequest $request): JsonResponse
    {
        $data = $request->validated();

        $location = AssetLocation::create([
            ...$data,
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenantContext->getTenantId(),
            'location_type' => $data['location_type'] ?? 'facility',
            'country' => $data['country'] ?? 'IT',
            'is_active' => $data['is_active'] ?? true,
        ]);

        return (new AssetLocationResource($location->fresh()))->response()->setStatusCode(201);
    }

    /**
     * Updates an asset location.
     */
    #[OA\Put(
        path: '/api/v1/eam/locations/{id}',
        operationId: 'updateEamLocation',
        description: 'Updates an asset location.',
        summary: 'Update asset location',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Catalogs'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamLocationId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementStoreAssetLocationRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Location updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementAssetLocationResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function updateLocation(StoreAssetLocationRequest $request, string $id): AssetLocationResource
    {
        $location = AssetLocation::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);
        $data = $request->validated();

        if (($data['parent_id'] ?? null) === (string) $location->id) {
            abort(422, 'A location cannot be its own parent.');
        }

        $location->update($data);

        return new AssetLocationResource($location->fresh());
    }
}
