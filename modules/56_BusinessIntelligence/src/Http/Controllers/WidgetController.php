<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\BusinessIntelligence\Http\Controllers\Concerns\InteractsWithBi;
use Modules\BusinessIntelligence\Http\Requests\DashboardDataRequest;
use Modules\BusinessIntelligence\Http\Requests\WidgetRequest;
use Modules\BusinessIntelligence\Http\Resources\WidgetResource;
use Modules\BusinessIntelligence\Models\BiDashboard;
use Modules\BusinessIntelligence\Models\BiDashboardWidget;
use Modules\BusinessIntelligence\Services\DashboardService;
use OpenApi\Attributes as OA;

/**
 * The widgets of a dashboard and their drill-down.
 */
final class WidgetController extends Controller
{
    use InteractsWithBi;

    public function __construct(private readonly DashboardService $dashboards) {}

    #[OA\Post(path: '/api/v1/business-intelligence/dashboards/{dashboardId}/widgets', operationId: 'storeBiWidget', description: 'Add a widget drawing on a saved query (`saved_query_id`) or on its own dataset and specification, never both. Without a full layout it takes the first free place of the grid; a layout that overlaps another widget or leaves the 12 columns is refused. A dashboard holds at most 30 widgets. `config.drill_path` lists the non-date dimensions a viewer can drill through.', summary: 'Add a widget', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BiWidgetRequest')), tags: ['Business Intelligence'], parameters: [new OA\Parameter(name: 'dashboardId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 201, description: 'Widget added', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BiWidget')])),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BiNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BiUnprocessable', response: 422),
    ])]
    public function store(WidgetRequest $request, string $dashboardId): JsonResponse
    {
        $dashboard = BiDashboard::query()->findOrFail($dashboardId);
        $this->authorize('update', $dashboard);

        return (new WidgetResource($this->dashboards->addWidget($dashboard, $this->currentUser(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/business-intelligence/widgets/{id}', operationId: 'updateBiWidget', description: 'Change a widget: title, type, source, options or place on the grid.', summary: 'Update a widget', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BiWidgetRequest')), tags: ['Business Intelligence'], parameters: [new OA\Parameter(ref: '#/components/parameters/BiId')], responses: [
        new OA\Response(response: 200, description: 'Widget updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BiWidget')])),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BiNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BiUnprocessable', response: 422),
    ])]
    public function update(WidgetRequest $request, string $id): WidgetResource
    {
        $widget = BiDashboardWidget::query()->findOrFail($id);
        $this->authorize('update', $widget->dashboard);

        return new WidgetResource($this->dashboards->updateWidget($widget, $this->currentUser(), $request->validated()));
    }

    #[OA\Delete(path: '/api/v1/business-intelligence/widgets/{id}', operationId: 'deleteBiWidget', description: 'Remove a widget from its dashboard.', summary: 'Delete a widget', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Business Intelligence'], parameters: [new OA\Parameter(ref: '#/components/parameters/BiId')], responses: [
        new OA\Response(response: 204, description: 'Widget deleted'),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BiNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        $widget = BiDashboardWidget::query()->findOrFail($id);
        $this->authorize('update', $widget->dashboard);
        $widget->delete();

        return response()->noContent();
    }

    #[OA\Get(path: '/api/v1/business-intelligence/widgets/{id}/data', operationId: 'showBiWidgetData', description: 'The figures of one widget with the filters of its dashboard. Drill-down: `drill[0][dimension]=customer&drill[0][value]=Rossi` filters on the first dimension of the drill path and shows the next one; each level must follow the path, and the last dimension of the path cannot be drilled further. `period` and `filters` replace the saved filters of the dashboard for this view.', summary: 'Data and drill-down of a widget', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Business Intelligence'], parameters: [new OA\Parameter(ref: '#/components/parameters/BiId')], responses: [
        new OA\Response(response: 200, description: 'Data of the widget', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BiWidgetData')])),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BiNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BiUnprocessable', response: 422),
    ])]
    public function data(DashboardDataRequest $request, string $id): JsonResponse
    {
        $widget = BiDashboardWidget::query()->findOrFail($id);
        $dashboard = $widget->dashboard;
        $this->authorize('view', $dashboard);

        return response()->json(['data' => ['widget_id' => $widget->id, 'status' => 'ok', 'error' => null, ...$this->dashboards->widgetData($dashboard, $widget, $this->currentUser(), $request->safe()->only(['period', 'filters', 'drill']))]]);
    }
}
