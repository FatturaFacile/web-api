<?php

declare(strict_types=1);

namespace Modules\Sustainability\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Sustainability\Http\Controllers\Concerns\InteractsWithSustainability;
use Modules\Sustainability\Http\Requests\ReportingPeriodRequest;
use Modules\Sustainability\Http\Resources\ReportingPeriodResource;
use Modules\Sustainability\Models\EsgReportingPeriod;
use Modules\Sustainability\Services\ReportingPeriodService;
use OpenApi\Attributes as OA;

/**
 * Reporting periods: open to collect data, closed to lock it.
 */
final class ReportingPeriodController extends Controller
{
    use InteractsWithSustainability;

    public function __construct(private readonly ReportingPeriodService $periods) {}

    #[OA\Get(path: '/api/v1/sustainability/periods', operationId: 'listEsgReportingPeriods', description: 'Reporting periods, newest first. Filter: status (open|closed).', summary: 'List reporting periods', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], responses: [
        new OA\Response(response: 200, description: 'Reporting periods', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SustainabilityPeriod'))])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', EsgReportingPeriod::class);
        $filters = $request->validate(['status' => ['nullable', Rule::in(EsgReportingPeriod::Statuses)]]);

        return ReportingPeriodResource::collection(
            EsgReportingPeriod::query()
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->orderByDesc('starts_on')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/sustainability/periods', operationId: 'storeEsgReportingPeriod', description: 'Open a reporting period. Periods of a company never overlap.', summary: 'Create a reporting period', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SustainabilityPeriodRequest')), tags: ['Sustainability'], responses: [
        new OA\Response(response: 201, description: 'Period created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityPeriod')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityUnprocessable', response: 422),
    ])]
    public function store(ReportingPeriodRequest $request): JsonResponse
    {
        $this->authorize('create', EsgReportingPeriod::class);

        return (new ReportingPeriodResource($this->periods->create($this->tenantId(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/sustainability/periods/{id}', operationId: 'showEsgReportingPeriod', description: 'Return a reporting period.', summary: 'Show a reporting period', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], parameters: [new OA\Parameter(ref: '#/components/parameters/SustainabilityId')], responses: [
        new OA\Response(response: 200, description: 'Period', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityPeriod')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
    ])]
    public function show(string $id): ReportingPeriodResource
    {
        $period = EsgReportingPeriod::query()->findOrFail($id);
        $this->authorize('view', $period);

        return new ReportingPeriodResource($period);
    }

    #[OA\Put(path: '/api/v1/sustainability/periods/{id}', operationId: 'updateEsgReportingPeriod', description: 'Change the name, the dates or the intensity denominators of an open period.', summary: 'Update a reporting period', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SustainabilityPeriodRequest')), tags: ['Sustainability'], parameters: [new OA\Parameter(ref: '#/components/parameters/SustainabilityId')], responses: [
        new OA\Response(response: 200, description: 'Period updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityPeriod')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SustainabilityUnprocessable', response: 422),
    ])]
    public function update(ReportingPeriodRequest $request, string $id): ReportingPeriodResource
    {
        $period = EsgReportingPeriod::query()->findOrFail($id);
        $this->authorize('update', $period);

        return new ReportingPeriodResource($this->periods->update($period, $request->validated()));
    }

    #[OA\Post(path: '/api/v1/sustainability/periods/{id}/close', operationId: 'closeEsgReportingPeriod', description: 'Close the period: the activity data and the KPI values dated inside it are locked.', summary: 'Close a reporting period', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], parameters: [new OA\Parameter(ref: '#/components/parameters/SustainabilityId')], responses: [
        new OA\Response(response: 200, description: 'Period closed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityPeriod')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SustainabilityUnprocessable', response: 422),
    ])]
    public function close(string $id): ReportingPeriodResource
    {
        $period = EsgReportingPeriod::query()->findOrFail($id);
        $this->authorize('update', $period);

        return new ReportingPeriodResource($this->periods->close($period, $this->userId()));
    }

    #[OA\Post(path: '/api/v1/sustainability/periods/{id}/reopen', operationId: 'reopenEsgReportingPeriod', description: 'Reopen a closed period. Owners, administrators and members granted sustainability.approve only.', summary: 'Reopen a reporting period', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], parameters: [new OA\Parameter(ref: '#/components/parameters/SustainabilityId')], responses: [
        new OA\Response(response: 200, description: 'Period reopened', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityPeriod')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SustainabilityUnprocessable', response: 422),
    ])]
    public function reopen(string $id): ReportingPeriodResource
    {
        $period = EsgReportingPeriod::query()->findOrFail($id);
        $this->authorize('approve', $period);

        return new ReportingPeriodResource($this->periods->reopen($period));
    }
}
