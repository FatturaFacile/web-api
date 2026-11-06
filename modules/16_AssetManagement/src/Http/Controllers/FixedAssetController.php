<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AssetManagement\Contracts\AssetLifecycleServiceInterface;
use Modules\AssetManagement\Contracts\ProjectAssetIntegrationServiceInterface;
use Modules\AssetManagement\DTOs\AllocateProjectAssetDTO;
use Modules\AssetManagement\DTOs\CapitalizeAssetDTO;
use Modules\AssetManagement\DTOs\DisposeAssetDTO;
use Modules\AssetManagement\DTOs\RegisterAssetDTO;
use Modules\AssetManagement\DTOs\TransferAssetDTO;
use Modules\AssetManagement\DTOs\ValuateAssetDTO;
use Modules\AssetManagement\Http\Requests\CapitalizeAssetRequest;
use Modules\AssetManagement\Http\Requests\DisposeAssetRequest;
use Modules\AssetManagement\Http\Requests\StoreFixedAssetRequest;
use Modules\AssetManagement\Http\Requests\TransferAssetRequest;
use Modules\AssetManagement\Http\Requests\ValuateAssetRequest;
use Modules\AssetManagement\Http\Resources\AssetDisposalResource;
use Modules\AssetManagement\Http\Resources\AssetTransferResource;
use Modules\AssetManagement\Http\Resources\AssetValuationResource;
use Modules\AssetManagement\Http\Resources\FixedAssetResource;
use Modules\AssetManagement\Models\FixedAsset;
use OpenApi\Attributes as OA;

final class FixedAssetController extends Controller
{
    public function __construct(
        private readonly AssetLifecycleServiceInterface $lifecycleService,
        private readonly ProjectAssetIntegrationServiceInterface $projectAssetService,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Returns the paginated list of assets of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/eam/assets',
        operationId: 'listEamAssets',
        description: 'Returns the paginated list of assets of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List assets',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filter by status', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'category_id', in: 'query', required: false, description: 'Filter by category', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of assets', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssetManagementFixedAssetResource')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $query = FixedAsset::where('tenant_id', $tenant->id);

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->has('category_id')) {
            $query->where('category_id', $request->query('category_id'));
        }

        $assets = $query->orderByDesc('created_at')->paginate(25);

        return response()->json([
            'data' => FixedAssetResource::collection($assets),
            'meta' => [
                'current_page' => $assets->currentPage(),
                'total' => $assets->total(),
            ],
        ]);
    }

    /**
     * Creates a new asset in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/eam/assets',
        operationId: 'createEamAsset',
        description: 'Creates a new asset in the active tenant.',
        summary: 'Create asset',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementStoreFixedAssetRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Asset created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementFixedAssetResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreFixedAssetRequest $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $actorId = (string) ($request->user()?->id ?? '');

        $asset = $this->lifecycleService->registerAsset(new RegisterAssetDTO(
            tenantId: (string) $tenant->id,
            companyId: (string) $tenant->id,
            name: (string) $request->input('name'),
            categoryId: (string) $request->input('category_id'),
            acquisitionCost: (float) $request->input('acquisition_cost'),
            acquisitionType: (string) $request->input('acquisition_type', 'purchase'),
            assetClassId: $request->input('asset_class_id'),
            locationId: $request->input('location_id'),
            parentAssetId: $request->input('parent_asset_id'),
            custodianUserId: $request->input('custodian_user_id'),
            projectId: $request->input('project_id'),
            barcodeTag: $request->input('barcode_tag'),
            serialNumber: $request->input('serial_number'),
            description: $request->input('description'),
            acquisitionDate: $request->input('acquisition_date'),
            salvageValue: (float) $request->input('salvage_value', 0.0),
            depreciationMethod: $request->input('depreciation_method'),
            usefulLifeYears: $request->input('useful_life_years') ? (int) $request->input('useful_life_years') : null,
            totalExpectedUnits: (float) $request->input('total_expected_units', 0.0),
            condition: (string) $request->input('condition', 'new'),
            specifications: $request->input('specifications', []),
            metadata: $request->input('metadata', [])
        ), $actorId);

        return response()->json(['data' => new FixedAssetResource($asset)], 201);
    }

    /**
     * Returns a single asset of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/eam/assets/{id}',
        operationId: 'getEamAsset',
        description: 'Returns a single asset of the active tenant.',
        summary: 'Get asset',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the asset', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementFixedAssetResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $asset = FixedAsset::where('tenant_id', $tenant->id)->findOrFail($id);

        return response()->json(['data' => new FixedAssetResource($asset)]);
    }

    /**
     * Updates an existing asset of the active tenant.
     */
    #[OA\Put(
        path: '/api/v1/eam/assets/{id}',
        operationId: 'updateEamAsset',
        description: 'Updates an existing asset of the active tenant.',
        summary: 'Update asset',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the asset', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'name'), new OA\Property(property: 'description'), new OA\Property(property: 'serial_number'), new OA\Property(property: 'barcode_tag'), new OA\Property(property: 'condition'), new OA\Property(property: 'location_id'), new OA\Property(property: 'specifications'), new OA\Property(property: 'metadata')])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementFixedAssetResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    #[OA\Patch(
        path: '/api/v1/eam/assets/{id}',
        operationId: 'patchEamAsset',
        description: 'Updates an existing asset of the active tenant.',
        summary: 'Update asset',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the asset', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'name'), new OA\Property(property: 'description'), new OA\Property(property: 'serial_number'), new OA\Property(property: 'barcode_tag'), new OA\Property(property: 'condition'), new OA\Property(property: 'location_id'), new OA\Property(property: 'specifications'), new OA\Property(property: 'metadata')])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementFixedAssetResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function update(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $asset = FixedAsset::where('tenant_id', $tenant->id)->findOrFail($id);

        $asset->update($request->only([
            'name', 'description', 'serial_number', 'barcode_tag', 'condition', 'location_id', 'specifications', 'metadata',
        ]));

        return response()->json(['data' => new FixedAssetResource($asset->fresh())]);
    }

    /**
     * Deletes a asset of the active tenant.
     */
    #[OA\Delete(
        path: '/api/v1/eam/assets/{id}',
        operationId: 'deleteEamAsset',
        description: 'Deletes a asset of the active tenant.',
        summary: 'Delete asset',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the asset', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $asset = FixedAsset::where('tenant_id', $tenant->id)->findOrFail($id);
        $asset->delete();

        return response()->json(['message' => 'Asset deleted']);
    }

    /**
     * Capitalizes a fixed asset with its cost and salvage value.
     */
    #[OA\Post(
        path: '/api/v1/eam/assets/{id}/capitalize',
        operationId: 'capitalizeEamAsset',
        description: 'Capitalizes a fixed asset with its cost and salvage value.',
        summary: 'Capitalize asset',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the asset', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementCapitalizeAssetRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementFixedAssetResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function capitalize(CapitalizeAssetRequest $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $asset = FixedAsset::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? '');

        $updated = $this->lifecycleService->capitalizeAsset(new CapitalizeAssetDTO(
            assetId: (string) $asset->id,
            capitalizedCost: (float) $request->input('capitalized_cost'),
            salvageValue: (float) $request->input('salvage_value', (float) $asset->salvage_value),
            capitalizationDate: $request->input('capitalization_date'),
            inServiceDate: $request->input('in_service_date'),
            depreciationMethod: $request->input('depreciation_method'),
            usefulLifeYears: $request->input('useful_life_years') ? (int) $request->input('useful_life_years') : null,
            autoPostToGl: (bool) $request->input('auto_post_to_gl', true)
        ), $actorId);

        return response()->json(['data' => new FixedAssetResource($updated)]);
    }

    /**
     * Puts a fixed asset into service at the given date.
     */
    #[OA\Post(
        path: '/api/v1/eam/assets/{id}/commission',
        operationId: 'commissionEamAsset',
        description: 'Puts a fixed asset into service at the given date.',
        summary: 'Commission asset',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the asset', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'commissioning_date')])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementFixedAssetResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function commission(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $asset = FixedAsset::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? '');

        $updated = $this->lifecycleService->commissionAsset(
            $asset,
            $request->input('commissioning_date'),
            $actorId
        );

        return response()->json(['data' => new FixedAssetResource($updated)]);
    }

    /**
     * Transfers a fixed asset to another location, custodian or project.
     */
    #[OA\Post(
        path: '/api/v1/eam/assets/{id}/transfer',
        operationId: 'transferEamAsset',
        description: 'Transfers a fixed asset to another location, custodian or project.',
        summary: 'Transfer asset',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the asset', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementTransferAssetRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Asset created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementAssetTransferResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function transfer(TransferAssetRequest $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $asset = FixedAsset::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? '');

        $transfer = $this->lifecycleService->transferAsset(new TransferAssetDTO(
            assetId: (string) $asset->id,
            toLocationId: $request->input('to_location_id'),
            toCustodianId: $request->input('to_custodian_id'),
            toProjectId: $request->input('to_project_id'),
            transferDate: $request->input('transfer_date'),
            reason: $request->input('reason')
        ), $actorId);

        return response()->json(['data' => new AssetTransferResource($transfer)], 201);
    }

    /**
     * Records a valuation of a fixed asset.
     */
    #[OA\Post(
        path: '/api/v1/eam/assets/{id}/valuate',
        operationId: 'valuateEamAsset',
        description: 'Records a valuation of a fixed asset.',
        summary: 'Valuate asset',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the asset', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementValuateAssetRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Asset created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementAssetValuationResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function valuate(ValuateAssetRequest $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $asset = FixedAsset::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? '');

        $valuation = $this->lifecycleService->valuateAsset(new ValuateAssetDTO(
            assetId: (string) $asset->id,
            valuationType: (string) $request->input('valuation_type'),
            fairValue: (float) $request->input('fair_value'),
            valueInUse: (float) $request->input('value_in_use', 0.0),
            valuationDate: $request->input('valuation_date'),
            appraiserName: $request->input('appraiser_name'),
            reason: $request->input('reason'),
            autoPostToGl: (bool) $request->input('auto_post_to_gl', true)
        ), $actorId);

        return response()->json(['data' => new AssetValuationResource($valuation)], 201);
    }

    /**
     * Records the disposal of a fixed asset.
     */
    #[OA\Post(
        path: '/api/v1/eam/assets/{id}/dispose',
        operationId: 'disposeEamAsset',
        description: 'Records the disposal of a fixed asset.',
        summary: 'Dispose asset',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the asset', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementDisposeAssetRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Asset created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementAssetDisposalResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function dispose(DisposeAssetRequest $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $asset = FixedAsset::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? '');

        $disposal = $this->lifecycleService->disposeAsset(new DisposeAssetDTO(
            assetId: (string) $asset->id,
            disposalType: (string) $request->input('disposal_type'),
            proceedsAmount: (float) $request->input('proceeds_amount', 0.0),
            disposalCosts: (float) $request->input('disposal_costs', 0.0),
            disposalDate: $request->input('disposal_date'),
            buyerContactId: $request->input('buyer_contact_id'),
            reason: $request->input('reason'),
            autoPostToGl: (bool) $request->input('auto_post_to_gl', true)
        ), $actorId);

        return response()->json(['data' => new AssetDisposalResource($disposal)], 201);
    }

    /**
     * Attaches a component to a fixed asset.
     */
    #[OA\Post(
        path: '/api/v1/eam/assets/{id}/components',
        operationId: 'attachComponentEamAsset',
        description: 'Attaches a component to a fixed asset. The whole request body is passed to the lifecycle service.',
        summary: 'Attach asset component',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the asset', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, description: 'Free-form attributes: the controller forwards the whole body to the domain service.', content: new OA\JsonContent(type: 'object', additionalProperties: true)),
        responses: [
            new OA\Response(response: 201, description: 'Asset created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementAssetComponentModel')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function attachComponent(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $asset = FixedAsset::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? '');

        $component = $this->lifecycleService->attachComponent($asset, $request->all(), $actorId);

        return response()->json(['data' => $component], 201);
    }

    /**
     * Assigns a custodian to a fixed asset, with an optional department.
     */
    #[OA\Post(
        path: '/api/v1/eam/assets/{id}/custodians',
        operationId: 'assignCustodianEamAsset',
        description: 'Assigns a custodian to a fixed asset, with an optional department.',
        summary: 'Assign asset custodian',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the asset', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'custodian_user_id', type: 'string'), new OA\Property(property: 'department')])),
        responses: [
            new OA\Response(response: 201, description: 'Asset created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementAssetCustodianModel')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function assignCustodian(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $asset = FixedAsset::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? '');

        $custody = $this->lifecycleService->assignCustodian(
            $asset,
            (string) $request->input('custodian_user_id'),
            $request->input('department'),
            $actorId
        );

        return response()->json(['data' => $custody], 201);
    }

    /**
     * Allocates a fixed asset to a project for a period.
     */
    #[OA\Post(
        path: '/api/v1/eam/assets/{id}/project-allocations',
        operationId: 'allocateToProjectEamAsset',
        description: 'Allocates a fixed asset to a project for a period.',
        summary: 'Allocate asset to project',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the asset', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'project_id', type: 'string'), new OA\Property(property: 'allocated_from', type: 'string'), new OA\Property(property: 'allocated_to'), new OA\Property(property: 'hourly_rate', type: 'number', default: 0), new OA\Property(property: 'daily_rate', type: 'number', default: 0), new OA\Property(property: 'utilized_hours', type: 'number', default: 0), new OA\Property(property: 'utilized_days', type: 'number', default: 0), new OA\Property(property: 'notes')])),
        responses: [
            new OA\Response(response: 201, description: 'Asset created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementProjectAssetAllocationModel')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function allocateToProject(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $asset = FixedAsset::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? '');

        $allocation = $this->projectAssetService->allocateAssetToProject(new AllocateProjectAssetDTO(
            tenantId: (string) $tenant->id,
            assetId: (string) $asset->id,
            projectId: (string) $request->input('project_id'),
            allocatedFrom: (string) $request->input('allocated_from', date('Y-m-d')),
            allocatedTo: $request->input('allocated_to'),
            hourlyRate: (float) $request->input('hourly_rate', 0.0),
            dailyRate: (float) $request->input('daily_rate', 0.0),
            utilizedHours: (float) $request->input('utilized_hours', 0.0),
            utilizedDays: (float) $request->input('utilized_days', 0.0),
            notes: $request->input('notes')
        ), $actorId);

        return response()->json(['data' => $allocation], 201);
    }

    /**
     * Reverses an impairment of a fixed asset up to the given recoverable amount.
     */
    #[OA\Post(
        path: '/api/v1/eam/assets/{id}/reverse-impairment',
        operationId: 'reverseImpairmentEamAsset',
        description: 'Reverses an impairment of a fixed asset up to the given recoverable amount.',
        summary: 'Reverse asset impairment',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the asset', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'recoverable_amount', type: 'number'), new OA\Property(property: 'reason')])),
        responses: [
            new OA\Response(response: 201, description: 'Asset created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementAssetValuationResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function reverseImpairment(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $asset = FixedAsset::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? '');

        $valuation = $this->lifecycleService->reverseImpairment(
            $asset,
            (float) $request->input('recoverable_amount'),
            $request->input('reason'),
            $actorId
        );

        return response()->json(['data' => new AssetValuationResource($valuation)], 201);
    }

    /**
     * Records an assignment of a fixed asset.
     */
    #[OA\Post(
        path: '/api/v1/eam/assets/{id}/assignments',
        operationId: 'assignAssetEamAsset',
        description: 'Records an assignment of a fixed asset. The whole request body is passed to the lifecycle service.',
        summary: 'Assign asset',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the asset', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, description: 'Free-form attributes: the controller forwards the whole body to the domain service.', content: new OA\JsonContent(type: 'object', additionalProperties: true)),
        responses: [
            new OA\Response(response: 201, description: 'Asset created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementAssetAssignmentModel')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function assignAsset(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $asset = FixedAsset::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? '');

        $assignment = $this->lifecycleService->assignAsset($asset, $request->all(), $actorId);

        return response()->json(['data' => $assignment], 201);
    }
}
