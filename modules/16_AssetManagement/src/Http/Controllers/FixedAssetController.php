<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\AssetManagement\Contracts\AssetLifecycleServiceInterface;
use Modules\AssetManagement\DTOs\CapitalizeAssetDTO;
use Modules\AssetManagement\DTOs\DisposeAssetDTO;
use Modules\AssetManagement\DTOs\RegisterAssetDTO;
use Modules\AssetManagement\DTOs\TransferAssetDTO;
use Modules\AssetManagement\DTOs\ValuateAssetDTO;
use Modules\AssetManagement\Http\Requests\AcquireAssetRequest;
use Modules\AssetManagement\Http\Requests\CapitalizeAssetRequest;
use Modules\AssetManagement\Http\Requests\CommissionAssetRequest;
use Modules\AssetManagement\Http\Requests\DisposeAssetRequest;
use Modules\AssetManagement\Http\Requests\ReverseImpairmentRequest;
use Modules\AssetManagement\Http\Requests\StoreFixedAssetRequest;
use Modules\AssetManagement\Http\Requests\TransferAssetRequest;
use Modules\AssetManagement\Http\Requests\UpdateFixedAssetRequest;
use Modules\AssetManagement\Http\Requests\ValuateAssetRequest;
use Modules\AssetManagement\Http\Resources\AssetDisposalResource;
use Modules\AssetManagement\Http\Resources\AssetTransferResource;
use Modules\AssetManagement\Http\Resources\AssetValuationResource;
use Modules\AssetManagement\Http\Resources\FixedAssetResource;
use Modules\AssetManagement\Models\AssetDisposal;
use Modules\AssetManagement\Models\AssetTransfer;
use Modules\AssetManagement\Models\AssetValuation;
use Modules\AssetManagement\Models\FixedAsset;
use OpenApi\Attributes as OA;

/**
 * Fixed assets: register, read and run the lifecycle operations (capitalization, commissioning, transfer, valuation,
 * impairment reversal, disposal). Writes need the write access of the assets module.
 */
final class FixedAssetController extends Controller
{
    public function __construct(
        private readonly AssetLifecycleServiceInterface $lifecycleService,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Paginated assets of the active company, filterable by status, category, location, cost centre and text.
     */
    #[OA\Get(
        path: '/api/v1/eam/assets',
        operationId: 'listEamAssets',
        description: 'Paginated assets of the active company. Filters: status, category_id, location_id, cost_center_id, search (number, name, serial, tag).',
        summary: 'List assets',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'category_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'location_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'cost_center_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(ref: '#/components/parameters/EamPage'),
            new OA\Parameter(ref: '#/components/parameters/EamPerPage'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Page of assets', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssetManagementFixedAssetResource'))])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = FixedAsset::where('tenant_id', $this->tenantContext->getTenantId());

        foreach (['status', 'category_id', 'location_id', 'cost_center_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, (string) $request->query($filter));
            }
        }

        if ($request->filled('search')) {
            $term = '%'.addcslashes((string) $request->query('search'), '%_\\').'%';
            $query->where(fn ($inner) => $inner->where('asset_number', 'ilike', $term)->orWhere('name', 'ilike', $term)->orWhere('serial_number', 'ilike', $term)->orWhere('barcode_tag', 'ilike', $term));
        }

        return FixedAssetResource::collection($query->orderByDesc('created_at')->paginate($this->perPage($request)));
    }

    /**
     * Registers a new asset (status registered, nothing posted).
     */
    #[OA\Post(
        path: '/api/v1/eam/assets',
        operationId: 'storeEamAsset',
        description: 'Registers a new asset. Rates and useful life default to those of the category.',
        summary: 'Register asset',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementStoreFixedAssetRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Asset registered', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementFixedAssetResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function store(StoreFixedAssetRequest $request): JsonResponse
    {
        $tenantId = (string) $this->tenantContext->getTenantId();
        $data = $request->validated();

        $asset = $this->lifecycleService->registerAsset(new RegisterAssetDTO(
            tenantId: $tenantId,
            companyId: $tenantId,
            name: (string) $data['name'],
            categoryId: (string) $data['category_id'],
            acquisitionCost: (string) $data['acquisition_cost'],
            acquisitionType: (string) ($data['acquisition_type'] ?? 'purchase'),
            assetClassId: $data['asset_class_id'] ?? null,
            locationId: $data['location_id'] ?? null,
            parentAssetId: $data['parent_asset_id'] ?? null,
            custodianUserId: $data['custodian_user_id'] ?? null,
            projectId: $data['project_id'] ?? null,
            supplierId: $data['supplier_id'] ?? null,
            assetNumber: $data['asset_number'] ?? null,
            barcodeTag: $data['barcode_tag'] ?? null,
            serialNumber: $data['serial_number'] ?? null,
            description: $data['description'] ?? null,
            acquisitionDate: $data['acquisition_date'] ?? null,
            salvageValue: (string) ($data['salvage_value'] ?? '0'),
            depreciationMethod: $data['depreciation_method'] ?? null,
            usefulLifeYears: isset($data['useful_life_years']) ? (int) $data['useful_life_years'] : null,
            totalExpectedUnits: (string) ($data['total_expected_units'] ?? '0'),
            replacementCost: (string) ($data['replacement_cost'] ?? '0'),
            currency: (string) ($data['currency'] ?? 'EUR'),
            condition: (string) ($data['condition'] ?? 'new'),
            specifications: $data['specifications'] ?? [],
            metadata: $data['metadata'] ?? [],
            costCenterId: $data['cost_center_id'] ?? null,
            warrantyExpiryDate: $data['warranty_expiry_date'] ?? null,
            warrantyProvider: $data['warranty_provider'] ?? null,
            insuranceProvider: $data['insurance_provider'] ?? null,
            insurancePolicyNumber: $data['insurance_policy_number'] ?? null,
            insuredValue: isset($data['insured_value']) ? (string) $data['insured_value'] : null,
            insuranceExpiryDate: $data['insurance_expiry_date'] ?? null,
            proRataConvention: $data['pro_rata_convention'] ?? null,
        ), $this->actor($request));

        return (new FixedAssetResource($asset))->response()->setStatusCode(201);
    }

    /**
     * Registers an asset from a passive invoice line or a purchase order item, optionally capitalizing it.
     */
    #[OA\Post(
        path: '/api/v1/eam/assets/acquire',
        operationId: 'acquireEamAsset',
        description: 'Registers an asset from a passive invoice line (cost = net line total, capitalization credited to the cost account of the line) or a purchase order item. Each line can become one asset only.',
        summary: 'Register asset from purchase document',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementAcquireAssetRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Asset registered', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementFixedAssetResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function acquire(AcquireAssetRequest $request): JsonResponse
    {
        $data = $request->validated();

        $asset = $this->lifecycleService->registerFromPurchaseDocument(
            (string) $this->tenantContext->getTenantId(),
            (string) $data['source_type'],
            (string) $data['source_id'],
            $data,
            $this->actor($request)
        );

        return (new FixedAssetResource($asset))->response()->setStatusCode(201);
    }

    /**
     * One asset of the active company.
     */
    #[OA\Get(
        path: '/api/v1/eam/assets/{id}',
        operationId: 'showEamAsset',
        description: 'Returns an asset of the active company.',
        summary: 'Get asset',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamAssetId')],
        responses: [
            new OA\Response(response: 200, description: 'Asset', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementFixedAssetResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
        ]
    )]
    public function show(string $id): FixedAssetResource
    {
        return new FixedAssetResource($this->asset($id));
    }

    /**
     * Updates the descriptive, warranty and insurance data of an asset.
     */
    #[OA\Put(
        path: '/api/v1/eam/assets/{id}',
        operationId: 'updateEamAsset',
        description: 'Updates the descriptive, warranty and insurance data of an asset. Values change only through the lifecycle operations. PATCH is accepted too.',
        summary: 'Update asset',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamAssetId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementUpdateFixedAssetRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Asset updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementFixedAssetResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    #[OA\Patch(
        path: '/api/v1/eam/assets/{id}',
        operationId: 'patchEamAsset',
        description: 'Same as PUT: updates the descriptive, warranty and insurance data of an asset; only the fields sent change.',
        summary: 'Update asset (PATCH)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamAssetId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementUpdateFixedAssetRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Asset updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementFixedAssetResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function update(UpdateFixedAssetRequest $request, string $id): FixedAssetResource
    {
        $asset = $this->asset($id);
        $this->authorize('update', $asset);

        return new FixedAssetResource($this->lifecycleService->updateAsset($asset, $request->validated(), $this->actor($request)));
    }

    /**
     * Deletes an asset that was never capitalized.
     */
    #[OA\Delete(
        path: '/api/v1/eam/assets/{id}',
        operationId: 'deleteEamAsset',
        description: 'Deletes a draft or registered asset without accounting history. Capitalized assets are disposed of instead.',
        summary: 'Delete asset',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamAssetId')],
        responses: [
            new OA\Response(response: 204, description: 'Asset deleted'),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function destroy(Request $request, string $id): JsonResponse
    {
        $asset = $this->asset($id);
        $this->authorize('delete', $asset);
        $this->lifecycleService->deleteAsset($asset, $this->actor($request));

        return response()->json(null, 204);
    }

    /**
     * Capitalizes an asset and posts the capitalization to the General Ledger.
     */
    #[OA\Post(
        path: '/api/v1/eam/assets/{id}/capitalize',
        operationId: 'capitalizeEamAsset',
        description: 'Capitalizes a registered asset, opens its statutory and tax books and posts Dr fixed asset / Cr acquisition clearing on the capitalization date. Above the threshold an active ASSET_CAPITALIZATION_APPROVAL workflow decides first.',
        summary: 'Capitalize asset',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamAssetId')],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementCapitalizeAssetRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Asset capitalized (or waiting for approval)', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementFixedAssetResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function capitalize(CapitalizeAssetRequest $request, string $id): FixedAssetResource
    {
        $asset = $this->asset($id);
        $this->authorize('capitalize', $asset);
        $data = $request->validated();

        return new FixedAssetResource($this->lifecycleService->capitalizeAsset(new CapitalizeAssetDTO(
            assetId: (string) $asset->id,
            capitalizedCost: (string) ($data['capitalized_cost'] ?? '0'),
            salvageValue: (string) ($data['salvage_value'] ?? $asset->salvage_value),
            capitalizationDate: $data['capitalization_date'] ?? null,
            inServiceDate: $data['in_service_date'] ?? null,
            depreciationMethod: $data['depreciation_method'] ?? null,
            usefulLifeYears: isset($data['useful_life_years']) ? (int) $data['useful_life_years'] : null,
            autoPostToGl: (bool) ($data['auto_post_to_gl'] ?? true),
            depreciationStartDate: $data['depreciation_start_date'] ?? null,
            proRataConvention: $data['pro_rata_convention'] ?? null,
        ), $this->actor($request)));
    }

    /**
     * Puts a capitalized asset in service.
     */
    #[OA\Post(
        path: '/api/v1/eam/assets/{id}/commission',
        operationId: 'commissionEamAsset',
        description: 'Puts a capitalized asset in service.',
        summary: 'Commission asset',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamAssetId')],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementCommissionAssetRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Asset in service', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementFixedAssetResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function commission(CommissionAssetRequest $request, string $id): FixedAssetResource
    {
        $asset = $this->asset($id);
        $this->authorize('update', $asset);

        return new FixedAssetResource($this->lifecycleService->commissionAsset($asset, $request->validated('commissioning_date'), $this->actor($request)));
    }

    /**
     * Transfers an asset to another location, custodian, project or cost centre.
     */
    #[OA\Post(
        path: '/api/v1/eam/assets/{id}/transfer',
        operationId: 'transferEamAsset',
        description: 'Transfers an asset to another location, custodian, project or cost centre. With an active ASSET_TRANSFER_APPROVAL workflow the transfer waits for approval.',
        summary: 'Transfer asset',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamAssetId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementTransferAssetRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Transfer recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementAssetTransferResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function transfer(TransferAssetRequest $request, string $id): JsonResponse
    {
        $asset = $this->asset($id);
        $this->authorize('transfer', $asset);
        $data = $request->validated();

        $transfer = $this->lifecycleService->transferAsset(new TransferAssetDTO(
            assetId: (string) $asset->id,
            toLocationId: $data['to_location_id'] ?? null,
            toCustodianId: $data['to_custodian_id'] ?? null,
            toProjectId: $data['to_project_id'] ?? null,
            transferDate: $data['transfer_date'] ?? null,
            reason: $data['reason'] ?? null,
            toCostCenterId: $data['to_cost_center_id'] ?? null,
        ), $this->actor($request));

        return (new AssetTransferResource($transfer))->response()->setStatusCode(201);
    }

    /**
     * Revalues or impairs an asset.
     */
    #[OA\Post(
        path: '/api/v1/eam/assets/{id}/valuate',
        operationId: 'valuateEamAsset',
        description: 'Revaluation (IAS 16) or impairment (IAS 36) of the statutory book. Impairments are charged first against the revaluation surplus of the asset; upward revaluations first reverse earlier losses. The new carrying amount is depreciated over the remaining useful life.',
        summary: 'Revalue or impair asset',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamAssetId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementValuateAssetRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Valuation recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementAssetValuationResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function valuate(ValuateAssetRequest $request, string $id): JsonResponse
    {
        $asset = $this->asset($id);
        $this->authorize('valuate', $asset);
        $data = $request->validated();

        $valuation = $this->lifecycleService->valuateAsset(new ValuateAssetDTO(
            assetId: (string) $asset->id,
            valuationType: (string) $data['valuation_type'],
            fairValue: (string) $data['fair_value'],
            valueInUse: (string) ($data['value_in_use'] ?? '0'),
            valuationDate: $data['valuation_date'] ?? null,
            appraiserName: $data['appraiser_name'] ?? null,
            reason: $data['reason'] ?? null,
            autoPostToGl: (bool) ($data['auto_post_to_gl'] ?? true),
        ), $this->actor($request));

        return (new AssetValuationResource($valuation))->response()->setStatusCode(201);
    }

    /**
     * Reverses accumulated impairment losses of an asset.
     */
    #[OA\Post(
        path: '/api/v1/eam/assets/{id}/reverse-impairment',
        operationId: 'reverseImpairmentEamAsset',
        description: 'Reverses accumulated impairment losses up to the new recoverable amount, through profit or loss.',
        summary: 'Reverse impairment',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamAssetId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementReverseImpairmentRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Reversal recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementAssetValuationResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function reverseImpairment(ReverseImpairmentRequest $request, string $id): JsonResponse
    {
        $asset = $this->asset($id);
        $this->authorize('valuate', $asset);

        $valuation = $this->lifecycleService->reverseImpairment($asset, (string) $request->validated('recoverable_amount'), $request->validated('reason'), $this->actor($request));

        return (new AssetValuationResource($valuation))->response()->setStatusCode(201);
    }

    /**
     * Disposes of an asset or of a percentage of it.
     */
    #[OA\Post(
        path: '/api/v1/eam/assets/{id}/dispose',
        operationId: 'disposeEamAsset',
        description: 'Total or partial disposal (sale, scrap, retirement, donation, theft or loss): the disposed share of every book is derecognised and the gain or loss is posted. Depreciate the asset up to the disposal period first.',
        summary: 'Dispose of asset',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamAssetId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementDisposeAssetRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Disposal recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementAssetDisposalResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function dispose(DisposeAssetRequest $request, string $id): JsonResponse
    {
        $asset = $this->asset($id);
        $this->authorize('dispose', $asset);
        $data = $request->validated();

        $disposal = $this->lifecycleService->disposeAsset(new DisposeAssetDTO(
            assetId: (string) $asset->id,
            disposalType: (string) $data['disposal_type'],
            proceedsAmount: (string) ($data['proceeds_amount'] ?? '0'),
            disposalCosts: (string) ($data['disposal_costs'] ?? '0'),
            disposalDate: $data['disposal_date'] ?? null,
            buyerContactId: $data['buyer_contact_id'] ?? null,
            reason: $data['reason'] ?? null,
            autoPostToGl: (bool) ($data['auto_post_to_gl'] ?? true),
            disposedPercentage: (string) ($data['disposed_percentage'] ?? '100'),
        ), $this->actor($request));

        return (new AssetDisposalResource($disposal))->response()->setStatusCode(201);
    }

    /**
     * Transfers, valuations and disposals of an asset, latest first.
     */
    #[OA\Get(
        path: '/api/v1/eam/assets/{id}/history',
        operationId: 'historyEamAsset',
        description: 'Transfers, valuations and disposals of an asset, latest first (at most 100 of each).',
        summary: 'Asset history',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamAssetId')],
        responses: [
            new OA\Response(response: 200, description: 'History', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'transfers', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssetManagementAssetTransferResource')),
                new OA\Property(property: 'valuations', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssetManagementAssetValuationResource')),
                new OA\Property(property: 'disposals', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssetManagementAssetDisposalResource')),
            ], type: 'object')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
        ]
    )]
    public function history(string $id): JsonResponse
    {
        $asset = $this->asset($id);
        $scope = static fn (string $model) => $model::where('tenant_id', $asset->tenant_id)->where('asset_id', $asset->id)->latest()->limit(100)->get();

        return response()->json(['data' => [
            'transfers' => AssetTransferResource::collection($scope(AssetTransfer::class)),
            'valuations' => AssetValuationResource::collection($scope(AssetValuation::class)),
            'disposals' => AssetDisposalResource::collection($scope(AssetDisposal::class)),
        ]]);
    }

    /**
     * The asset of the active company, 404 otherwise.
     */
    private function asset(string $id): FixedAsset
    {
        return FixedAsset::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);
    }

    /**
     * Rows per page, between 1 and 100.
     */
    private function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->query('per_page', '25')));
    }

    /**
     * The authenticated user id.
     */
    private function actor(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id !== null ? (string) $id : null;
    }
}
