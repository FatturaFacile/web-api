<?php

declare(strict_types=1);

namespace Modules\Sustainability\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Sustainability\Http\Controllers\Concerns\InteractsWithSustainability;
use Modules\Sustainability\Http\Requests\EsrsDatapointRequest;
use Modules\Sustainability\Http\Requests\EsrsValuesRequest;
use Modules\Sustainability\Http\Resources\EsrsDatapointResource;
use Modules\Sustainability\Models\EsgEsrsDatapoint;
use Modules\Sustainability\Models\EsgEsrsDatapointValue;
use Modules\Sustainability\Models\EsgReportingPeriod;
use Modules\Sustainability\Services\EsrsReportingService;
use OpenApi\Attributes as OA;

/**
 * CSRD/ESRS data point catalog, the values of a reporting period and the completeness of the reporting package.
 */
final class EsrsController extends Controller
{
    use InteractsWithSustainability;

    public function __construct(private readonly EsrsReportingService $esrs) {}

    #[OA\Get(path: '/api/v1/sustainability/esrs/datapoints', operationId: 'listEsgEsrsDatapoints', description: 'The ESRS data point catalog of the company by standard (ESRS2, E1-E5, S1-S4, G1). Filters: standard, active=1.', summary: 'List ESRS data points', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], parameters: [
        new OA\Parameter(name: 'standard', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['ESRS2', 'E1', 'E2', 'E3', 'E4', 'E5', 'S1', 'S2', 'S3', 'S4', 'G1'])),
        new OA\Parameter(name: 'active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
    ], responses: [
        new OA\Response(response: 200, description: 'Data points', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SustainabilityEsrsDatapoint'))])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
    ])]
    public function datapoints(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', EsgEsrsDatapoint::class);

        return EsrsDatapointResource::collection(
            EsgEsrsDatapoint::query()
                ->where('tenant_id', $this->tenantId())
                ->when($request->filled('standard'), static fn ($query) => $query->where('standard', (string) $request->query('standard')))
                ->when($request->boolean('active'), static fn ($query) => $query->where('is_active', true))
                ->orderBy('sort_order')
                ->orderBy('code')
                ->get(),
        );
    }

    #[OA\Post(path: '/api/v1/sustainability/esrs/datapoints', operationId: 'storeEsgEsrsDatapoint', description: 'Adds a custom data point to the catalog.', summary: 'Create an ESRS data point', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SustainabilityEsrsDatapointRequest')), tags: ['Sustainability'], responses: [
        new OA\Response(response: 201, description: 'Data point created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityEsrsDatapoint')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityUnprocessable', response: 422),
    ])]
    public function storeDatapoint(EsrsDatapointRequest $request): JsonResponse
    {
        $this->authorize('create', EsgEsrsDatapoint::class);

        return (new EsrsDatapointResource($this->esrs->createDatapoint($this->tenantId(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/sustainability/esrs/datapoints/{id}', operationId: 'updateEsgEsrsDatapoint', description: 'Changes a data point (the code never changes). Deactivate a data point to leave it out of the reporting package.', summary: 'Update an ESRS data point', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SustainabilityEsrsDatapointRequest')), tags: ['Sustainability'], parameters: [new OA\Parameter(ref: '#/components/parameters/SustainabilityId')], responses: [
        new OA\Response(response: 200, description: 'Data point updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityEsrsDatapoint')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SustainabilityUnprocessable', response: 422),
    ])]
    public function updateDatapoint(EsrsDatapointRequest $request, string $id): EsrsDatapointResource
    {
        $datapoint = EsgEsrsDatapoint::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('update', $datapoint);

        return new EsrsDatapointResource($this->esrs->updateDatapoint($datapoint, $request->validated()));
    }

    #[OA\Post(path: '/api/v1/sustainability/esrs/install-catalog', operationId: 'installEsgEsrsCatalog', description: 'Installs the default ESRS data point catalog (ESRS 2, E1-E5, S1-S4, G1); data points that already exist are kept. Returns the number created.', summary: 'Install the default ESRS catalog', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], responses: [
        new OA\Response(response: 200, description: 'Catalog installed', content: new OA\JsonContent(properties: [new OA\Property(property: 'created', type: 'integer')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
    ])]
    public function installCatalog(): JsonResponse
    {
        $this->authorize('create', EsgEsrsDatapoint::class);

        return response()->json(['created' => $this->esrs->installCatalog($this->tenantId())]);
    }

    #[OA\Get(path: '/api/v1/sustainability/periods/{id}/esrs', operationId: 'showEsgEsrsPackage', description: 'The data points of the catalog with their value and review status for the period, and whether the approved double materiality assessment makes each of them required. Filter: standard.', summary: 'ESRS reporting package of a period', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], parameters: [
        new OA\Parameter(ref: '#/components/parameters/SustainabilityId'),
        new OA\Parameter(name: 'standard', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
    ], responses: [
        new OA\Response(response: 200, description: 'Package', content: new OA\JsonContent(ref: '#/components/schemas/SustainabilityEsrsPackage')),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
    ])]
    public function package(Request $request, string $id): JsonResponse
    {
        $period = $this->period($id);
        $this->authorize('view', $period);
        $package = $this->esrs->packageOf($period, $request->filled('standard') ? (string) $request->query('standard') : null);

        return response()->json(['data' => [
            'period_id' => $period->id,
            'materiality' => $package['materiality'],
            'datapoints' => array_map(fn (array $row): array => [
                ...(new EsrsDatapointResource($row['datapoint']))->resolve(),
                'is_required' => $row['is_required'],
                'value' => $row['value'] === null ? null : $this->valueOf($row['value']),
            ], $package['rows']),
        ]]);
    }

    #[OA\Put(path: '/api/v1/sustainability/periods/{id}/esrs/values', operationId: 'recordEsgEsrsValues', description: 'Records the value, review status and omission reason of several data points. The approved status is reserved to owners, administrators and members granted sustainability.approve; a not_applicable data point needs an omission reason.', summary: 'Record ESRS data point values', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SustainabilityEsrsValuesRequest')), tags: ['Sustainability'], parameters: [new OA\Parameter(ref: '#/components/parameters/SustainabilityId')], responses: [
        new OA\Response(response: 200, description: 'Values recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SustainabilityEsrsValue'))])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SustainabilityUnprocessable', response: 422),
    ])]
    public function recordValues(EsrsValuesRequest $request, string $id): JsonResponse
    {
        $period = $this->period($id);
        $this->authorize('update', $period);
        $mayApprove = $request->user()?->can('approve', $period) ?? false;
        $saved = $this->esrs->recordValues($period, $request->validated('values'), $this->userId(), $mayApprove);

        return response()->json(['data' => array_map(fn (EsgEsrsDatapointValue $value): array => $this->valueOf($value), $saved)]);
    }

    #[OA\Post(path: '/api/v1/sustainability/periods/{id}/esrs/sync-inventory', operationId: 'syncEsgEsrsFromInventory', description: 'Fills the data points that have an automatic source (Scope 1, 2, 3 and total emissions, intensity, headcount) from the carbon inventory of the period as drafts. Approved values are kept.', summary: 'Fill ESRS data points from the inventory', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], parameters: [new OA\Parameter(ref: '#/components/parameters/SustainabilityId')], responses: [
        new OA\Response(response: 200, description: 'Synchronised', content: new OA\JsonContent(properties: [new OA\Property(property: 'updated', type: 'integer'), new OA\Property(property: 'skipped_approved', type: 'integer')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
    ])]
    public function syncInventory(string $id): JsonResponse
    {
        $period = $this->period($id);
        $this->authorize('update', $period);

        return response()->json($this->esrs->syncFromInventory($period, $this->userId()));
    }

    #[OA\Get(path: '/api/v1/sustainability/periods/{id}/esrs/completeness', operationId: 'showEsgEsrsCompleteness', description: 'Completeness check of the reporting package: required data points per standard (decided by the approved double materiality assessment), the missing ones with the reason, blocking issues and warnings (Scope 3 categories without data, open supplier requests, open period).', summary: 'Reporting package completeness', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], parameters: [new OA\Parameter(ref: '#/components/parameters/SustainabilityId')], responses: [
        new OA\Response(response: 200, description: 'Completeness', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityEsrsCompleteness')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
    ])]
    public function completeness(string $id): JsonResponse
    {
        $period = $this->period($id);
        $this->authorize('view', $period);

        return response()->json(['data' => $this->esrs->completeness($period)]);
    }

    private function period(string $id): EsgReportingPeriod
    {
        return EsgReportingPeriod::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function valueOf(EsgEsrsDatapointValue $value): array
    {
        return [
            'id' => $value->id,
            'datapoint_id' => $value->datapoint_id,
            'status' => $value->status,
            'value_number' => $value->value_number,
            'value_text' => $value->value_text,
            'value_boolean' => $value->value_boolean,
            'source' => $value->source,
            'omission_reason' => $value->omission_reason,
            'notes' => $value->notes,
        ];
    }
}
