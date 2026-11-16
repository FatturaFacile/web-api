<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\BusinessIntelligence\Http\Controllers\Concerns\InteractsWithBi;
use Modules\BusinessIntelligence\Http\Requests\DashboardDataRequest;
use Modules\BusinessIntelligence\Http\Requests\DashboardRequest;
use Modules\BusinessIntelligence\Http\Requests\LayoutRequest;
use Modules\BusinessIntelligence\Http\Resources\DashboardResource;
use Modules\BusinessIntelligence\Models\BiDashboard;
use Modules\BusinessIntelligence\Services\DashboardService;
use Modules\BusinessIntelligence\Services\SharingService;
use OpenApi\Attributes as OA;

/**
 * Configurable dashboards: the grid of widgets, the filters shared by all of them and the data of the whole dashboard.
 */
final class DashboardController extends Controller
{
    use InteractsWithBi;

    public function __construct(private readonly DashboardService $dashboards, private readonly SharingService $sharing) {}

    #[OA\Get(path: '/api/v1/business-intelligence/dashboards', operationId: 'listBiDashboards', description: 'Dashboards the caller can open: their own, the ones shared with the company and the ones shared with them. Filter: search (name).', summary: 'List dashboards', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Business Intelligence'], responses: [
        new OA\Response(response: 200, description: 'Dashboards', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/BiDashboard'))])),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', BiDashboard::class);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100']]);

        return DashboardResource::collection(
            BiDashboard::query()
                ->visibleTo($this->currentUser())
                ->with('owner:id,name')
                ->when(isset($filters['search']), static fn ($query) => $query->where('name', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%'))
                ->orderBy('name')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/business-intelligence/dashboards', operationId: 'storeBiDashboard', description: 'Create an empty dashboard, private to its creator. Filters: an optional period (preset or from and to) and dimension filters that apply to every widget whose dataset has the dimension.', summary: 'Create a dashboard', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BiDashboardRequest')), tags: ['Business Intelligence'], responses: [
        new OA\Response(response: 201, description: 'Dashboard created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BiDashboard')])),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BiUnprocessable', response: 422),
    ])]
    public function store(DashboardRequest $request): JsonResponse
    {
        $this->authorize('create', BiDashboard::class);
        $dashboard = $this->dashboards->create($this->tenantId(), $this->currentUser(), $request->validated());

        return (new DashboardResource($dashboard->load('widgets')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/business-intelligence/dashboards/{id}', operationId: 'showBiDashboard', description: 'Return a dashboard with its widgets and their place on the grid. The figures come from the data endpoint.', summary: 'Show a dashboard', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Business Intelligence'], parameters: [new OA\Parameter(ref: '#/components/parameters/BiId')], responses: [
        new OA\Response(response: 200, description: 'Dashboard', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BiDashboard')])),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BiNotFound', response: 404),
    ])]
    public function show(string $id): DashboardResource
    {
        $dashboard = BiDashboard::query()->with(['widgets', 'owner:id,name'])->findOrFail($id);
        $this->authorize('view', $dashboard);

        return new DashboardResource($dashboard);
    }

    #[OA\Put(path: '/api/v1/business-intelligence/dashboards/{id}', operationId: 'updateBiDashboard', description: 'Change the name, description, visibility or filters. Allowed to the owner and to members the dashboard is shared with for editing.', summary: 'Update a dashboard', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BiDashboardRequest')), tags: ['Business Intelligence'], parameters: [new OA\Parameter(ref: '#/components/parameters/BiId')], responses: [
        new OA\Response(response: 200, description: 'Dashboard updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BiDashboard')])),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BiNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BiUnprocessable', response: 422),
    ])]
    public function update(DashboardRequest $request, string $id): DashboardResource
    {
        $dashboard = BiDashboard::query()->findOrFail($id);
        $this->authorize('update', $dashboard);
        $updated = $this->dashboards->update($dashboard, $request->validated());
        $this->sharing->syncVisibility($updated);

        return new DashboardResource($updated->refresh()->load('widgets'));
    }

    #[OA\Delete(path: '/api/v1/business-intelligence/dashboards/{id}', operationId: 'deleteBiDashboard', description: 'Delete a dashboard with its widgets and shares. Only the owner, or an owner or administrator of the company.', summary: 'Delete a dashboard', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Business Intelligence'], parameters: [new OA\Parameter(ref: '#/components/parameters/BiId')], responses: [
        new OA\Response(response: 204, description: 'Dashboard deleted'),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BiNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        $dashboard = BiDashboard::query()->findOrFail($id);
        $this->authorize('delete', $dashboard);
        $dashboard->widgets()->delete();
        $this->sharing->purge($dashboard);
        $dashboard->delete();

        return response()->noContent();
    }

    #[OA\Put(path: '/api/v1/business-intelligence/dashboards/{id}/layout', operationId: 'updateBiDashboardLayout', description: 'Moves and resizes widgets in one call. The whole new grid is checked: every rectangle stays inside the 12 columns and no two rectangles overlap, so widgets can swap places.', summary: 'Save the layout of a dashboard', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BiLayoutRequest')), tags: ['Business Intelligence'], parameters: [new OA\Parameter(ref: '#/components/parameters/BiId')], responses: [
        new OA\Response(response: 200, description: 'Dashboard with the new layout', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BiDashboard')])),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BiNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BiUnprocessable', response: 422),
    ])]
    public function layout(LayoutRequest $request, string $id): DashboardResource
    {
        $dashboard = BiDashboard::query()->findOrFail($id);
        $this->authorize('update', $dashboard);
        $this->dashboards->relayout($dashboard, array_values((array) $request->validated('layout')));

        return new DashboardResource($dashboard->refresh()->load('widgets'));
    }

    #[OA\Get(path: '/api/v1/business-intelligence/dashboards/{id}/data', operationId: 'showBiDashboardData', description: 'The figures of every widget of the dashboard, with the permissions of the caller. The query parameters `period` and `filters` replace the saved filters of the dashboard for this view only. A widget the caller cannot draw (dataset of a module they cannot read, deleted saved query) carries its error and the others are returned.', summary: 'Data of a dashboard', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Business Intelligence'], parameters: [new OA\Parameter(ref: '#/components/parameters/BiId')], responses: [
        new OA\Response(response: 200, description: 'Data of the widgets', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/BiWidgetData'))])),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BiNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BiUnprocessable', response: 422),
    ])]
    public function data(DashboardDataRequest $request, string $id): JsonResponse
    {
        $dashboard = BiDashboard::query()->findOrFail($id);
        $this->authorize('view', $dashboard);

        return response()->json(['data' => $this->dashboards->data($dashboard, $this->currentUser(), $request->safe()->only(['period', 'filters']))]);
    }
}
