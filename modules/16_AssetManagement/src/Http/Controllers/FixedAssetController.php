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

final class FixedAssetController extends Controller
{
    public function __construct(
        private readonly AssetLifecycleServiceInterface $lifecycleService,
        private readonly ProjectAssetIntegrationServiceInterface $projectAssetService,
        private readonly TenantContext $tenantContext
    ) {}

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

    public function show(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $asset = FixedAsset::where('tenant_id', $tenant->id)->findOrFail($id);

        return response()->json(['data' => new FixedAssetResource($asset)]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $asset = FixedAsset::where('tenant_id', $tenant->id)->findOrFail($id);

        $asset->update($request->only([
            'name', 'description', 'serial_number', 'barcode_tag', 'condition', 'location_id', 'specifications', 'metadata',
        ]));

        return response()->json(['data' => new FixedAssetResource($asset->fresh())]);
    }

    public function destroy(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $asset = FixedAsset::where('tenant_id', $tenant->id)->findOrFail($id);
        $asset->delete();

        return response()->json(['message' => 'Asset deleted']);
    }

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

    public function attachComponent(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $asset = FixedAsset::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? '');

        $component = $this->lifecycleService->attachComponent($asset, $request->all(), $actorId);

        return response()->json(['data' => $component], 201);
    }

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

    public function assignAsset(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $asset = FixedAsset::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? '');

        $assignment = $this->lifecycleService->assignAsset($asset, $request->all(), $actorId);

        return response()->json(['data' => $assignment], 201);
    }
}
