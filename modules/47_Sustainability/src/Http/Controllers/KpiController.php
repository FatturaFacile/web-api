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
use Modules\Sustainability\Http\Requests\KpiDefinitionRequest;
use Modules\Sustainability\Http\Requests\KpiValueRequest;
use Modules\Sustainability\Http\Resources\KpiDefinitionResource;
use Modules\Sustainability\Http\Resources\KpiValueResource;
use Modules\Sustainability\Models\EsgKpiDefinition;
use Modules\Sustainability\Models\EsgKpiValue;
use Modules\Sustainability\Models\EsgReportingPeriod;
use Modules\Sustainability\Services\KpiService;
use OpenApi\Attributes as OA;

/**
 * ESG indicator definitions and their value per reporting period.
 */
final class KpiController extends Controller
{
    use InteractsWithSustainability;

    public function __construct(private readonly KpiService $kpis) {}

    #[OA\Get(path: '/api/v1/sustainability/kpis', operationId: 'listEsgKpiDefinitions', description: 'ESG indicator definitions. Filters: pillar (environmental|social|governance), is_active (0|1).', summary: 'List ESG KPI definitions', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], responses: [
        new OA\Response(response: 200, description: 'Definitions', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SustainabilityKpiDefinition'))])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', EsgKpiDefinition::class);
        $filters = $request->validate(['pillar' => ['nullable', Rule::in(EsgKpiDefinition::Pillars)], 'is_active' => ['nullable', 'boolean']]);

        return KpiDefinitionResource::collection(
            EsgKpiDefinition::query()
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['pillar']), static fn ($query) => $query->where('pillar', $filters['pillar']))
                ->when(isset($filters['is_active']), static fn ($query) => $query->where('is_active', (bool) $filters['is_active']))
                ->orderBy('pillar')
                ->orderBy('code')
                ->get(),
        );
    }

    #[OA\Post(path: '/api/v1/sustainability/kpis', operationId: 'storeEsgKpiDefinition', description: 'Define an ESG indicator. The code is unique in the company.', summary: 'Create an ESG KPI definition', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SustainabilityKpiDefinitionRequest')), tags: ['Sustainability'], responses: [
        new OA\Response(response: 201, description: 'Definition created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityKpiDefinition')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityUnprocessable', response: 422),
    ])]
    public function store(KpiDefinitionRequest $request): JsonResponse
    {
        $this->authorize('create', EsgKpiDefinition::class);

        return (new KpiDefinitionResource($this->kpis->createDefinition($this->tenantId(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/sustainability/kpis/{id}', operationId: 'updateEsgKpiDefinition', description: 'Change an indicator definition (the code is fixed). Set is_active to false to retire it without losing its values.', summary: 'Update an ESG KPI definition', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SustainabilityKpiDefinitionRequest')), tags: ['Sustainability'], parameters: [new OA\Parameter(ref: '#/components/parameters/SustainabilityId')], responses: [
        new OA\Response(response: 200, description: 'Definition updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityKpiDefinition')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SustainabilityUnprocessable', response: 422),
    ])]
    public function update(KpiDefinitionRequest $request, string $id): KpiDefinitionResource
    {
        $definition = EsgKpiDefinition::query()->findOrFail($id);
        $this->authorize('update', $definition);

        return new KpiDefinitionResource($this->kpis->updateDefinition($definition, $request->validated()));
    }

    #[OA\Post(path: '/api/v1/sustainability/kpis/install-defaults', operationId: 'installEsgDefaultKpis', description: 'Install the example ESRS-oriented indicators (energy, water, waste, headcount, gender split, accidents, governance), idempotent by code.', summary: 'Install the example KPIs', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], responses: [
        new OA\Response(response: 200, description: 'Number of definitions created', content: new OA\JsonContent(properties: [new OA\Property(property: 'created', type: 'integer')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
    ])]
    public function installDefaults(): JsonResponse
    {
        $this->authorize('create', EsgKpiDefinition::class);

        return response()->json(['created' => $this->kpis->installDefaults($this->tenantId())]);
    }

    #[OA\Get(path: '/api/v1/sustainability/kpi-values', operationId: 'listEsgKpiValues', description: 'Indicator values with their definition and whether the target is met. Filters: reporting_period_id, kpi_definition_id.', summary: 'List ESG KPI values', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], responses: [
        new OA\Response(response: 200, description: 'Values', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SustainabilityKpiValue'))])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
    ])]
    public function values(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', EsgKpiValue::class);
        $filters = $request->validate(['reporting_period_id' => ['nullable', 'uuid'], 'kpi_definition_id' => ['nullable', 'uuid']]);

        return KpiValueResource::collection(
            EsgKpiValue::query()
                ->with('definition')
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['reporting_period_id']), static fn ($query) => $query->where('reporting_period_id', $filters['reporting_period_id']))
                ->when(isset($filters['kpi_definition_id']), static fn ($query) => $query->where('kpi_definition_id', $filters['kpi_definition_id']))
                ->orderBy('created_at')
                ->get(),
        );
    }

    #[OA\Put(path: '/api/v1/sustainability/kpi-values', operationId: 'recordEsgKpiValue', description: 'Record the value of an indicator for a reporting period; recording it again replaces the value. Refused for a closed period or an inactive indicator.', summary: 'Record an ESG KPI value', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SustainabilityKpiValueRequest')), tags: ['Sustainability'], responses: [
        new OA\Response(response: 201, description: 'Value recorded for the first time', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityKpiValue')])),
        new OA\Response(response: 200, description: 'Value replaced', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityKpiValue')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityUnprocessable', response: 422),
    ])]
    public function record(KpiValueRequest $request): KpiValueResource
    {
        $this->authorize('create', EsgKpiValue::class);
        $data = $request->validated();
        $definition = EsgKpiDefinition::query()->findOrFail($data['kpi_definition_id']);
        $period = EsgReportingPeriod::query()->findOrFail($data['reporting_period_id']);

        return new KpiValueResource($this->kpis->record($this->tenantId(), $definition, $period, $data, $this->userId())->load('definition'));
    }
}
