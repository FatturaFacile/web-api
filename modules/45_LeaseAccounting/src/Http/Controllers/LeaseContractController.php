<?php

declare(strict_types=1);

namespace Modules\LeaseAccounting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\LeaseAccounting\Http\Controllers\Concerns\InteractsWithLeases;
use Modules\LeaseAccounting\Http\Requests\ActivateLeaseRequest;
use Modules\LeaseAccounting\Http\Requests\CancelLeaseRequest;
use Modules\LeaseAccounting\Http\Requests\LeaseContractRequest;
use Modules\LeaseAccounting\Http\Requests\RemeasureLeaseRequest;
use Modules\LeaseAccounting\Http\Resources\LeaseContractResource;
use Modules\LeaseAccounting\Http\Resources\LeaseScheduleLineResource;
use Modules\LeaseAccounting\Http\Resources\LeaseScheduleVersionResource;
use Modules\LeaseAccounting\Models\LeaseContract;
use Modules\LeaseAccounting\Models\LeaseScheduleLine;
use Modules\LeaseAccounting\Models\LeaseScheduleVersion;
use Modules\LeaseAccounting\Services\LeaseContractService;
use Modules\LeaseAccounting\Services\LeaseMeasurementService;
use OpenApi\Attributes as OA;

/**
 * Lease contracts of the lessee: terms, activation (initial measurement), remeasurement and the schedule.
 */
final class LeaseContractController extends Controller
{
    use InteractsWithLeases;

    public function __construct(
        private readonly LeaseContractService $contracts,
        private readonly LeaseMeasurementService $measurement,
    ) {}

    /**
     * List leases.
     */
    #[OA\Get(path: '/api/v1/leases/contracts', operationId: 'listLeaseContracts', description: 'Paginated leases of the company, newest first. Filters: status, asset_class, currency, exemption, search (number, title or lessor).', summary: 'List leases', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Leases'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['draft', 'active', 'expired', 'cancelled'])),
        new OA\Parameter(name: 'asset_class', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['real_estate', 'vehicles', 'machinery', 'it_equipment', 'other'])),
        new OA\Parameter(name: 'currency', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['EUR', 'CHF'])),
        new OA\Parameter(name: 'exemption', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['none', 'short_term', 'low_value'])),
        new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
    ], responses: [
        new OA\Response(response: 200, description: 'Leases', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/LeaseContract'))])),
        new OA\Response(ref: '#/components/responses/LeasesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/LeasesForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', LeaseContract::class);
        $search = trim((string) $request->query('search', ''));

        return LeaseContractResource::collection(
            LeaseContract::query()
                ->where('tenant_id', $this->tenantId())
                ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
                ->when($request->filled('asset_class'), static fn ($query) => $query->where('asset_class', (string) $request->query('asset_class')))
                ->when($request->filled('currency'), static fn ($query) => $query->where('currency', (string) $request->query('currency')))
                ->when($request->filled('exemption'), static fn ($query) => $query->where('exemption', (string) $request->query('exemption')))
                ->when($search !== '', static fn ($query) => $query->where(static function ($inner) use ($search): void {
                    $inner->where('number', 'like', "%{$search}%")->orWhere('title', 'like', "%{$search}%")->orWhere('lessor_name', 'like', "%{$search}%");
                }))
                ->orderByDesc('commencement_date')
                ->orderByDesc('number')
                ->gridPaginate($this->perPage())
        );
    }

    /**
     * Create lease.
     */
    #[OA\Post(path: '/api/v1/leases/contracts', operationId: 'storeLeaseContract', description: 'Drafts a lease. The number is progressive per company and year (LS-2027-0001). The term must be a whole number of payment periods (an extension reasonably certain to be exercised counts); the short-term (12 months or less, no purchase option reasonably certain) and low-value (asset worth the threshold of the currency or less when new) exemptions are checked here.', summary: 'Create a lease', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/LeaseContractRequest')), tags: ['Leases'], responses: [
        new OA\Response(response: 201, description: 'Lease drafted', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/LeaseContract')])),
        new OA\Response(ref: '#/components/responses/LeasesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/LeasesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/LeasesUnprocessable', response: 422),
    ])]
    public function store(LeaseContractRequest $request): JsonResponse
    {
        $this->authorize('create', LeaseContract::class);
        $contract = $this->contracts->create($this->tenantId(), $request->validated(), $this->userId());

        return (new LeaseContractResource($contract->refresh()))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Show lease.
     */
    #[OA\Get(path: '/api/v1/leases/contracts/{id}', operationId: 'showLeaseContract', description: 'A lease with its schedule versions.', summary: 'Show a lease', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Leases'], parameters: [new OA\Parameter(ref: '#/components/parameters/LeasesId')], responses: [
        new OA\Response(response: 200, description: 'Lease', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/LeaseContract')])),
        new OA\Response(ref: '#/components/responses/LeasesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/LeasesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/LeasesNotFound', response: 404),
    ])]
    public function show(string $id): LeaseContractResource
    {
        $contract = $this->findContract($id);
        $this->authorize('view', $contract);

        return new LeaseContractResource($contract->load('versions'));
    }

    /**
     * Update lease.
     */
    #[OA\Put(path: '/api/v1/leases/contracts/{id}', operationId: 'updateLeaseContract', description: 'Changes the terms of a draft lease. An activated lease is never edited: its terms change through a remeasurement.', summary: 'Update a draft lease', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/LeaseContractRequest')), tags: ['Leases'], parameters: [new OA\Parameter(ref: '#/components/parameters/LeasesId')], responses: [
        new OA\Response(response: 200, description: 'Lease updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/LeaseContract')])),
        new OA\Response(ref: '#/components/responses/LeasesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/LeasesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/LeasesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/LeasesUnprocessable', response: 422),
    ])]
    public function update(LeaseContractRequest $request, string $id): LeaseContractResource
    {
        $contract = $this->findContract($id);
        $this->authorize('update', $contract);

        return new LeaseContractResource($this->contracts->update($contract, $request->validated()));
    }

    /**
     * Delete lease.
     */
    #[OA\Delete(path: '/api/v1/leases/contracts/{id}', operationId: 'deleteLeaseContract', description: 'Deletes a draft lease. A lease that was activated is kept for the ledger and the disclosures.', summary: 'Delete a draft lease', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Leases'], parameters: [new OA\Parameter(ref: '#/components/parameters/LeasesId')], responses: [
        new OA\Response(response: 204, description: 'Lease deleted'),
        new OA\Response(ref: '#/components/responses/LeasesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/LeasesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/LeasesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/LeasesUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $contract = $this->findContract($id);
        $this->authorize('delete', $contract);
        $this->contracts->delete($contract);

        return response()->noContent();
    }

    /**
     * Preview the measurement.
     */
    #[OA\Get(path: '/api/v1/leases/contracts/{id}/preview', operationId: 'previewLeaseMeasurement', description: 'The initial measurement a draft lease would get on activation, without saving anything: lease liability (present value of the payments at the incremental borrowing rate, effective-rate method), right-of-use asset (liability plus initial direct costs minus incentives), undiscounted total and the full monthly schedule.', summary: 'Preview the initial measurement', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Leases'], parameters: [new OA\Parameter(ref: '#/components/parameters/LeasesId')], responses: [
        new OA\Response(response: 200, description: 'Measurement', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/LeaseMeasurementPreview')])),
        new OA\Response(ref: '#/components/responses/LeasesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/LeasesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/LeasesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/LeasesUnprocessable', response: 422),
    ])]
    public function preview(string $id): JsonResponse
    {
        $contract = $this->findContract($id);
        $this->authorize('view', $contract);

        return response()->json(['data' => $this->measurement->preview($contract)]);
    }

    /**
     * Activate lease.
     */
    #[OA\Post(path: '/api/v1/leases/contracts/{id}/activate', operationId: 'activateLeaseContract', description: 'Activates a draft: measures the lease, writes schedule version 1 and, unless post_recognition is false, posts the recognition journal at the commencement date through the general ledger (the fiscal period must be open). A lease with an exemption becomes active without a schedule: it is expensed.', summary: 'Activate a lease', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/LeaseActivateRequest')), tags: ['Leases'], parameters: [new OA\Parameter(ref: '#/components/parameters/LeasesId')], responses: [
        new OA\Response(response: 200, description: 'Lease activated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/LeaseContract')])),
        new OA\Response(ref: '#/components/responses/LeasesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/LeasesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/LeasesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/LeasesUnprocessable', response: 422),
    ])]
    public function activate(ActivateLeaseRequest $request, string $id): LeaseContractResource
    {
        $contract = $this->findContract($id);
        $this->authorize('post', $contract);

        return new LeaseContractResource($this->measurement->activate($contract, $request->boolean('post_recognition', true), $this->userId())->load('versions'));
    }

    /**
     * Remeasure lease.
     */
    #[OA\Post(path: '/api/v1/leases/contracts/{id}/remeasure', operationId: 'remeasureLeaseContract', description: 'Measures an active lease again from a period start (effective_date must be the first day of a month of the schedule not yet posted). reason: modification and reassessment use the revised discount rate new_discount_rate_percent and may change the payment and the remaining term; index_change keeps the original rate, needs new_payment_amount and keeps the term. The change of the liability adjusts the right-of-use asset; a reduction beyond its carrying amount is a gain. A new schedule version replaces the rows from the effective date on; the rows before stay.', summary: 'Remeasure a lease', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/LeaseRemeasureRequest')), tags: ['Leases'], parameters: [new OA\Parameter(ref: '#/components/parameters/LeasesId')], responses: [
        new OA\Response(response: 201, description: 'New schedule version', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/LeaseScheduleVersion')])),
        new OA\Response(ref: '#/components/responses/LeasesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/LeasesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/LeasesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/LeasesUnprocessable', response: 422),
    ])]
    public function remeasure(RemeasureLeaseRequest $request, string $id): JsonResponse
    {
        $contract = $this->findContract($id);
        $this->authorize('post', $contract);
        $version = $this->measurement->remeasure($contract, $request->validated(), $this->userId());

        return (new LeaseScheduleVersionResource($version))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Cancel lease.
     */
    #[OA\Post(path: '/api/v1/leases/contracts/{id}/cancel', operationId: 'cancelLeaseContract', description: 'Cancels a draft lease with a reason. An activated lease is never cancelled.', summary: 'Cancel a draft lease', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/LeaseCancelRequest')), tags: ['Leases'], parameters: [new OA\Parameter(ref: '#/components/parameters/LeasesId')], responses: [
        new OA\Response(response: 200, description: 'Lease cancelled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/LeaseContract')])),
        new OA\Response(ref: '#/components/responses/LeasesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/LeasesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/LeasesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/LeasesUnprocessable', response: 422),
    ])]
    public function cancel(CancelLeaseRequest $request, string $id): LeaseContractResource
    {
        $contract = $this->findContract($id);
        $this->authorize('update', $contract);

        return new LeaseContractResource($this->contracts->cancel($contract, (string) $request->validated('reason')));
    }

    /**
     * Schedule of a lease.
     */
    #[OA\Get(path: '/api/v1/leases/contracts/{id}/schedule', operationId: 'showLeaseSchedule', description: 'The monthly schedule in force: interest, payment, principal and closing balance of the lease liability, depreciation and carrying amount of the right-of-use asset, with the status of each month (scheduled or posted). With version_number, the rows that version produced, including the ones a later remeasurement superseded.', summary: 'Lease schedule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Leases'], parameters: [
        new OA\Parameter(ref: '#/components/parameters/LeasesId'),
        new OA\Parameter(name: 'version_number', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
    ], responses: [
        new OA\Response(response: 200, description: 'Schedule rows', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/LeaseScheduleLine'))])),
        new OA\Response(ref: '#/components/responses/LeasesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/LeasesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/LeasesNotFound', response: 404),
    ])]
    public function schedule(Request $request, string $id): AnonymousResourceCollection
    {
        $contract = $this->findContract($id);
        $this->authorize('view', $contract);

        if ($request->filled('version_number')) {
            $version = LeaseScheduleVersion::query()->where('lease_contract_id', $contract->id)->where('version_number', (int) $request->query('version_number'))->firstOrFail();

            return LeaseScheduleLineResource::collection($version->lines()->get());
        }

        return LeaseScheduleLineResource::collection(LeaseScheduleLine::query()->where('lease_contract_id', $contract->id)->where('status', '!=', 'superseded')->orderBy('period_number')->get());
    }

    /**
     * Versions of a lease.
     */
    #[OA\Get(path: '/api/v1/leases/contracts/{id}/versions', operationId: 'listLeaseVersions', description: 'The measurements of a lease, oldest first: the initial one and every remeasurement with its effect on the liability, the asset and the result.', summary: 'Lease schedule versions', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Leases'], parameters: [new OA\Parameter(ref: '#/components/parameters/LeasesId')], responses: [
        new OA\Response(response: 200, description: 'Versions', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/LeaseScheduleVersion'))])),
        new OA\Response(ref: '#/components/responses/LeasesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/LeasesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/LeasesNotFound', response: 404),
    ])]
    public function versions(string $id): AnonymousResourceCollection
    {
        $contract = $this->findContract($id);
        $this->authorize('view', $contract);

        return LeaseScheduleVersionResource::collection($contract->versions()->get());
    }
}
