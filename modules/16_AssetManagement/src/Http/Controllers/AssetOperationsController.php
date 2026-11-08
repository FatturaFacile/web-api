<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\AssetManagement\Contracts\AssetLifecycleServiceInterface;
use Modules\AssetManagement\Contracts\MaintenanceManagementServiceInterface;
use Modules\AssetManagement\Contracts\ProjectAssetIntegrationServiceInterface;
use Modules\AssetManagement\DTOs\AllocateProjectAssetDTO;
use Modules\AssetManagement\Http\Requests\AllocateProjectAssetRequest;
use Modules\AssetManagement\Http\Requests\AssetTagsRequest;
use Modules\AssetManagement\Http\Requests\AssignAssetRequest;
use Modules\AssetManagement\Http\Requests\AssignCustodianRequest;
use Modules\AssetManagement\Http\Requests\AttachComponentRequest;
use Modules\AssetManagement\Http\Requests\RetireComponentRequest;
use Modules\AssetManagement\Http\Requests\StoreMeterReadingRequest;
use Modules\AssetManagement\Http\Resources\AssetAssignmentResource;
use Modules\AssetManagement\Http\Resources\AssetBookResource;
use Modules\AssetManagement\Http\Resources\AssetComponentResource;
use Modules\AssetManagement\Http\Resources\AssetCustodianResource;
use Modules\AssetManagement\Http\Resources\AssetMeterReadingResource;
use Modules\AssetManagement\Http\Resources\ProjectAssetAllocationResource;
use Modules\AssetManagement\Models\AssetBook;
use Modules\AssetManagement\Models\AssetComponent;
use Modules\AssetManagement\Models\AssetMeterReading;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\AssetManagement\Services\AssetTagService;
use OpenApi\Attributes as OA;

/**
 * Structure and operations around an asset: components, custody, assignments, project allocations, parallel books,
 * tag data, meter readings and maintenance totals.
 */
final class AssetOperationsController extends Controller
{
    public function __construct(
        private readonly AssetLifecycleServiceInterface $lifecycleService,
        private readonly ProjectAssetIntegrationServiceInterface $projectAssetService,
        private readonly MaintenanceManagementServiceInterface $maintenanceService,
        private readonly AssetTagService $tags,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Components of an asset.
     */
    #[OA\Get(
        path: '/api/v1/eam/assets/{id}/components',
        operationId: 'listEamAssetComponents',
        description: 'Components of an asset (at most 200).',
        summary: 'List components',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamAssetId')],
        responses: [
            new OA\Response(response: 200, description: 'Components', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssetManagementAssetComponentResource'))])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
        ]
    )]
    public function components(string $id): AnonymousResourceCollection
    {
        $asset = $this->asset($id);

        return AssetComponentResource::collection(AssetComponent::where('tenant_id', $asset->tenant_id)->where('parent_asset_id', $asset->id)->orderBy('component_code')->limit(200)->get());
    }

    /**
     * Adds a component to an asset.
     */
    #[OA\Post(
        path: '/api/v1/eam/assets/{id}/components',
        operationId: 'storeEamAssetComponent',
        description: 'Adds a component; the cost allocated to the active components cannot exceed the cost of the asset.',
        summary: 'Attach component',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamAssetId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementAttachComponentRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Component attached', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementAssetComponentResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function attachComponent(AttachComponentRequest $request, string $id): JsonResponse
    {
        $asset = $this->asset($id);
        $this->authorize('update', $asset);

        return (new AssetComponentResource($this->lifecycleService->attachComponent($asset, $request->validated(), $this->actor($request))))->response()->setStatusCode(201);
    }

    /**
     * Marks a component replaced, detached or failed.
     */
    #[OA\Post(
        path: '/api/v1/eam/assets/{id}/components/{componentId}/retire',
        operationId: 'retireEamAssetComponent',
        description: 'Marks an active component replaced (default), detached or failed.',
        summary: 'Retire component',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamAssetId'), new OA\Parameter(ref: '#/components/parameters/EamComponentId')],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementRetireComponentRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Component retired', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementAssetComponentResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function retireComponent(RetireComponentRequest $request, string $id, string $componentId): AssetComponentResource
    {
        $asset = $this->asset($id);
        $this->authorize('update', $asset);

        return new AssetComponentResource($this->lifecycleService->retireComponent($asset, $componentId, (string) ($request->validated('status') ?? 'replaced'), $this->actor($request)));
    }

    /**
     * Gives an asset to a custodian.
     */
    #[OA\Post(
        path: '/api/v1/eam/assets/{id}/custodians',
        operationId: 'storeEamAssetCustodian',
        description: 'Gives the asset to a member of the company; the previous custody is returned.',
        summary: 'Assign custodian',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamAssetId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementAssignCustodianRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Custody recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementAssetCustodianResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function assignCustodian(AssignCustodianRequest $request, string $id): JsonResponse
    {
        $asset = $this->asset($id);
        $this->authorize('update', $asset);

        $custody = $this->lifecycleService->assignCustodian($asset, (string) $request->validated('custodian_user_id'), $request->validated('department'), $this->actor($request));

        return (new AssetCustodianResource($custody))->response()->setStatusCode(201);
    }

    /**
     * Assigns an asset to an employee, department, project or site.
     */
    #[OA\Post(
        path: '/api/v1/eam/assets/{id}/assignments',
        operationId: 'storeEamAssetAssignment',
        description: 'Assigns the asset; the previous active assignment is returned.',
        summary: 'Assign asset',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamAssetId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementAssignAssetRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Assignment recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementAssetAssignmentResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function assignAsset(AssignAssetRequest $request, string $id): JsonResponse
    {
        $asset = $this->asset($id);
        $this->authorize('update', $asset);

        return (new AssetAssignmentResource($this->lifecycleService->assignAsset($asset, $request->validated(), $this->actor($request))))->response()->setStatusCode(201);
    }

    /**
     * Allocates an asset to a project.
     */
    #[OA\Post(
        path: '/api/v1/eam/assets/{id}/project-allocations',
        operationId: 'storeEamAssetProjectAllocation',
        description: 'Allocates the asset to a project of the company with its utilization rates.',
        summary: 'Allocate asset to project',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamAssetId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementAllocateProjectAssetRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Allocation recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementProjectAssetAllocationResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function allocateToProject(AllocateProjectAssetRequest $request, string $id): JsonResponse
    {
        $asset = $this->asset($id);
        $this->authorize('update', $asset);
        $data = $request->validated();

        $allocation = $this->projectAssetService->allocateAssetToProject(new AllocateProjectAssetDTO(
            tenantId: (string) $asset->tenant_id,
            assetId: (string) $asset->id,
            projectId: (string) $data['project_id'],
            allocatedFrom: (string) ($data['allocated_from'] ?? now()->toDateString()),
            allocatedTo: $data['allocated_to'] ?? null,
            hourlyRate: (string) ($data['hourly_rate'] ?? '0'),
            dailyRate: (string) ($data['daily_rate'] ?? '0'),
            utilizedHours: (string) ($data['utilized_hours'] ?? '0'),
            utilizedDays: (string) ($data['utilized_days'] ?? '0'),
            notes: $data['notes'] ?? null
        ), $this->actor($request));

        return (new ProjectAssetAllocationResource($allocation))->response()->setStatusCode(201);
    }

    /**
     * Parallel books of an asset.
     */
    #[OA\Get(
        path: '/api/v1/eam/assets/{id}/books',
        operationId: 'listEamAssetBooks',
        description: 'Parallel books of the asset: statutory (civil, posted to the ledger), tax (fiscal), and IFRS or management when used.',
        summary: 'List asset books',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Depreciation'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamAssetId')],
        responses: [
            new OA\Response(response: 200, description: 'Books', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssetManagementAssetBookResource'))])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
        ]
    )]
    public function books(string $id): AnonymousResourceCollection
    {
        $asset = $this->asset($id);

        return AssetBookResource::collection(AssetBook::where('tenant_id', $asset->tenant_id)->where('asset_id', $asset->id)->orderBy('ledger_type')->get());
    }

    /**
     * Tag data (barcode and QR payload) of an asset.
     */
    #[OA\Get(
        path: '/api/v1/eam/assets/{id}/tag',
        operationId: 'showEamAssetTag',
        description: 'Data to print on the asset tag: Code 128 barcode value, QR payload and label lines.',
        summary: 'Asset tag data',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamAssetId')],
        responses: [
            new OA\Response(response: 200, description: 'Tag data', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementAssetTag')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
        ]
    )]
    public function tag(string $id): JsonResponse
    {
        return response()->json(['data' => $this->tags->tagData($this->asset($id))]);
    }

    /**
     * Tag data of several assets, for batch label printing.
     */
    #[OA\Get(
        path: '/api/v1/eam/assets/tags',
        operationId: 'listEamAssetTags',
        description: 'Tag data of up to 200 assets of the company (asset_ids[] in the query), for batch label printing.',
        summary: 'Batch asset tag data',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [new OA\Parameter(name: 'asset_ids[]', in: 'query', required: true, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), maxItems: 200))],
        responses: [
            new OA\Response(response: 200, description: 'Tag data', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssetManagementAssetTag'))])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function tags(AssetTagsRequest $request): JsonResponse
    {
        $assets = FixedAsset::where('tenant_id', $this->tenantContext->getTenantId())
            ->whereIn('id', (array) $request->validated('asset_ids'))
            ->with('location')
            ->orderBy('asset_number')
            ->get();

        return response()->json(['data' => $assets->map(fn (FixedAsset $asset): array => $this->tags->tagData($asset))->values()]);
    }

    /**
     * Meter readings of an asset, latest first.
     */
    #[OA\Get(
        path: '/api/v1/eam/assets/{id}/meter-readings',
        operationId: 'listEamAssetMeterReadings',
        description: 'Meter readings of the asset, latest first, optionally of one meter.',
        summary: 'List meter readings',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Maintenance'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamAssetId'), new OA\Parameter(name: 'meter_code', in: 'query', required: false, schema: new OA\Schema(type: 'string')), new OA\Parameter(ref: '#/components/parameters/EamPage'), new OA\Parameter(ref: '#/components/parameters/EamPerPage')],
        responses: [
            new OA\Response(response: 200, description: 'Readings', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssetManagementAssetMeterReadingResource'))])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
        ]
    )]
    public function meterReadings(Request $request, string $id): AnonymousResourceCollection
    {
        $asset = $this->asset($id);
        $query = AssetMeterReading::where('tenant_id', $asset->tenant_id)->where('asset_id', $asset->id);

        if ($request->filled('meter_code')) {
            $query->where('meter_code', (string) $request->query('meter_code'));
        }

        return AssetMeterReadingResource::collection($query->orderByDesc('read_at')->paginate(max(1, min(100, (int) $request->query('per_page', '25')))));
    }

    /**
     * Records a meter reading of an asset.
     */
    #[OA\Post(
        path: '/api/v1/eam/assets/{id}/meter-readings',
        operationId: 'storeEamAssetMeterReading',
        description: 'Records a cumulative meter reading; it cannot be lower than the previous reading of the same meter. Meter-based plans become due when the reading reaches their next due meter.',
        summary: 'Record meter reading',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Maintenance'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamAssetId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementStoreMeterReadingRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Reading recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementAssetMeterReadingResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function storeMeterReading(StoreMeterReadingRequest $request, string $id): JsonResponse
    {
        $asset = $this->asset($id);
        $data = $request->validated();

        $reading = $this->maintenanceService->recordMeterReading($asset, (string) $data['meter_code'], (string) $data['reading_value'], $data['read_at'] ?? null, $data['notes'] ?? null, $this->actor($request));

        return (new AssetMeterReadingResource($reading))->response()->setStatusCode(201);
    }

    /**
     * Maintenance totals of an asset.
     */
    #[OA\Get(
        path: '/api/v1/eam/assets/{id}/maintenance-summary',
        operationId: 'showEamAssetMaintenanceSummary',
        description: 'Work orders, maintenance costs, capitalized improvements, downtime and mean days between corrective failures of the asset.',
        summary: 'Asset maintenance summary',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Maintenance'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamAssetId')],
        responses: [
            new OA\Response(response: 200, description: 'Summary', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementMaintenanceSummary')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
        ]
    )]
    public function maintenanceSummary(string $id): JsonResponse
    {
        return response()->json(['data' => $this->maintenanceService->maintenanceSummary($this->asset($id))]);
    }

    /**
     * The asset of the active company, 404 otherwise.
     */
    private function asset(string $id): FixedAsset
    {
        return FixedAsset::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);
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
