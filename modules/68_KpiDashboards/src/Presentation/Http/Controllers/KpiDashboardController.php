<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Modules\KpiDashboards\Application\Commands\ConfigureKpiThresholdAlertCommand;
use Modules\KpiDashboards\Application\Commands\RefreshAnalyticalCubesCommand;
use Modules\KpiDashboards\Application\Commands\SaveCustomDashboardLayoutCommand;
use Modules\KpiDashboards\Application\Handlers\ConfigureKpiThresholdAlertHandler;
use Modules\KpiDashboards\Application\Handlers\DrillDownKpiToSourceDocumentsHandler;
use Modules\KpiDashboards\Application\Handlers\GetExecutiveKpiCockpitHandler;
use Modules\KpiDashboards\Application\Handlers\RefreshAnalyticalCubesHandler;
use Modules\KpiDashboards\Application\Handlers\SaveCustomDashboardLayoutHandler;
use Modules\KpiDashboards\Application\Queries\DrillDownKpiToSourceDocumentsQuery;
use Modules\KpiDashboards\Application\Queries\GetExecutiveKpiCockpitQuery;
use Modules\KpiDashboards\Domain\Enums\KpiCode;
use Modules\KpiDashboards\Domain\Enums\RoleCode;
use Modules\KpiDashboards\Domain\Exceptions\KpiAccessDeniedException;
use Modules\KpiDashboards\Domain\Exceptions\KpiDashboardException;
use Modules\KpiDashboards\Domain\Exceptions\KpiNotFoundException;
use Modules\KpiDashboards\Infrastructure\Models\KpiThresholdAlertLog;
use Modules\KpiDashboards\Infrastructure\Models\KpiThresholdRule;
use Modules\KpiDashboards\Presentation\Http\Requests\CockpitRequest;
use Modules\KpiDashboards\Presentation\Http\Requests\ConfigureThresholdAlertRequest;
use Modules\KpiDashboards\Presentation\Http\Requests\DrillDownRequest;
use Modules\KpiDashboards\Presentation\Http\Requests\RefreshCubesRequest;
use Modules\KpiDashboards\Presentation\Http\Requests\SaveDashboardLayoutRequest;
use Modules\KpiDashboards\Presentation\Http\Resources\KpiDashboardResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class KpiDashboardController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Get(path: '/api/v1/kpi-dashboards/cockpit', operationId: 'getExecutiveKpiCockpit', description: 'Every widget of a role cockpit (ceo, cfo, sales, operations, hr, custom) or a saved dashboard under the global filters: period or preset, comparison (yoy, mom, ytd, budget), legal entities, cost centers, display currency (CHF or EUR) and the intercompany elimination toggle. Flow KPIs convert at the average SNB/ECB rate, stock KPIs at the closing rate.', summary: 'Role cockpit with all KPI widgets', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['KPI Dashboards'], responses: [new OA\Response(response: 200, description: 'The cockpit', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 403, description: 'The role may not open this cockpit')])]
    public function cockpit(CockpitRequest $request, GetExecutiveKpiCockpitHandler $handler): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse($handler->handle(new GetExecutiveKpiCockpitQuery(
            $this->user(),
            $request->toFilter($this->tenantId()),
            $request->filled('role') ? RoleCode::from((string) $request->input('role')) : null,
            $request->filled('dashboard_id') ? (string) $request->input('dashboard_id') : null,
        ))));
    }

    #[OA\Get(path: '/api/v1/kpi-dashboards/drill-down/{kpi}', operationId: 'drillDownKpiToSourceDocuments', description: 'The paginated, access-filtered source documents (journal entries, invoices, orders, opportunities, employees, timesheets, production orders) behind a KPI card or chart segment, with the breadcrumb and the deep link into the ERP module. export=csv streams the rows.', summary: 'One-click drill-down to source documents', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['KPI Dashboards'], parameters: [new OA\Parameter(name: 'kpi', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'The documents'), new OA\Response(response: 403, description: 'The role may not drill into this KPI')])]
    public function drillDown(string $kpi, DrillDownRequest $request, DrillDownKpiToSourceDocumentsHandler $handler): JsonResponse|StreamedResponse
    {
        $code = KpiCode::tryFrom($kpi);

        if ($code === null) {
            return $this->problemDetailsResponse('Not Found', "Unknown KPI [{$kpi}].", Response::HTTP_NOT_FOUND);
        }

        $export = $request->input('export') === 'csv';
        $result = $this->guarded(fn (): array => $handler->handle(new DrillDownKpiToSourceDocumentsQuery(
            $this->user(),
            $request->toFilter($this->tenantId()),
            $code,
            $request->drillSegment(),
            (int) $request->input('page', 1),
            (int) $request->input('per_page', 25),
            $export,
        )));

        if ($result instanceof JsonResponse) {
            return $result;
        }

        return $export ? $this->csv($code, $result) : $this->successResponse($result);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function csv(KpiCode $kpi, array $result): StreamedResponse
    {
        return response()->streamDownload(static function () use ($result): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, $result['columns'], ',', '"', '');

            foreach ($result['rows'] as $row) {
                // A spreadsheet runs a cell that starts with these characters as a formula.
                fputcsv($out, array_map(static function (string $column) use ($row): string {
                    $cell = (string) ($row[$column] ?? '');

                    return $cell !== '' && in_array($cell[0], ['=', '+', '-', '@'], true) && ! is_numeric($cell) ? "'".$cell : $cell;
                }, $result['columns']), ',', '"', '');
            }

            fclose($out);
        }, "kpi-{$kpi->value}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    #[OA\Get(path: '/api/v1/kpi-dashboards/catalog', operationId: 'listKpiCatalog', description: 'The KPIs a widget can show: role, default visualization, whether the amount is converted, and the documents behind it.', summary: 'KPI catalog for the widget picker', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['KPI Dashboards'], responses: [new OA\Response(response: 200, description: 'The catalog')])]
    public function catalog(): JsonResponse
    {
        return $this->successResponse(array_map(static fn (KpiCode $kpi): array => [
            'kpi_code' => $kpi->value,
            'role_code' => $kpi->role()->value,
            'widget_type' => $kpi->widgetType()->value,
            'is_monetary' => $kpi->isMonetary(),
            'is_stock' => $kpi->isStock(),
            'source_document_type' => $kpi->sourceDocumentType(),
            'title_key' => 'KPI_DASHBOARDS.KPI_'.strtoupper($kpi->value),
            'description_key' => 'KPI_DASHBOARDS.KPI_'.strtoupper($kpi->value).'_DESC',
        ], KpiCode::cases()));
    }

    #[OA\Post(path: '/api/v1/kpi-dashboards/layout', operationId: 'saveCustomDashboardLayout', description: 'Clones a system cockpit into the user\'s own layout or updates the user\'s custom layout: widget positions on the 12-column grid, sizes, types, default comparison and thresholds.', summary: 'Save a custom cockpit layout', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['KPI Dashboards'], responses: [new OA\Response(response: 200, description: 'The saved cockpit'), new OA\Response(response: 422, description: 'Invalid layout')])]
    public function saveLayout(SaveDashboardLayoutRequest $request, SaveCustomDashboardLayoutHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(fn (): JsonResponse => $this->successResponse(new KpiDashboardResource($handler->handle(new SaveCustomDashboardLayoutCommand(
            $this->tenantId(),
            $this->user() ?? throw new KpiAccessDeniedException('Sign in to save a cockpit.'),
            $data['dashboard_id'] ?? null,
            RoleCode::tryFrom((string) ($data['role'] ?? 'custom')) ?? RoleCode::Custom,
            $data['custom_title'] ?? null,
            (int) $data['refresh_interval_seconds'],
            $data['widgets'],
            isset($data['expected_version']) ? (int) $data['expected_version'] : null,
        ))), 'Layout saved'));
    }

    #[OA\Post(path: '/api/v1/kpi-dashboards/cubes/refresh', operationId: 'refreshAnalyticalCubes', description: 'Drops the pre-aggregated KPI payloads of the tenant (debounced). Postings, orders, stock movements and timesheets do this automatically.', summary: 'Refresh the analytical cubes', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['KPI Dashboards'], responses: [new OA\Response(response: 200, description: 'Refreshed or debounced')])]
    public function refreshCubes(RefreshCubesRequest $request, RefreshAnalyticalCubesHandler $handler): JsonResponse
    {
        return $this->successResponse($handler->handle(new RefreshAnalyticalCubesCommand($this->tenantId())));
    }

    #[OA\Get(path: '/api/v1/kpi-dashboards/alerts', operationId: 'listKpiThresholdAlerts', description: 'The threshold rules of the tenant and the latest breaches they raised.', summary: 'Threshold rules and alert log', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['KPI Dashboards'], responses: [new OA\Response(response: 200, description: 'Rules and alerts')])]
    public function alerts(): JsonResponse
    {
        return $this->successResponse([
            'rules' => KpiThresholdRule::query()->where('tenant_id', $this->tenantId())->orderBy('kpi_code')->get()->map(static fn (KpiThresholdRule $rule): array => [
                'id' => $rule->id, 'kpi_code' => $rule->kpi_code->value, 'operator' => $rule->operator, 'warning_value' => $rule->warning_value, 'critical_value' => $rule->critical_value,
                'channels' => $rule->channels_json, 'cooldown_minutes' => $rule->cooldown_minutes, 'is_active' => $rule->is_active,
            ])->all(),
            'alerts' => KpiThresholdAlertLog::query()->where('tenant_id', $this->tenantId())->latest('triggered_at')->limit(50)->get()->map(static fn (KpiThresholdAlertLog $alert): array => [
                'id' => $alert->id, 'rule_id' => $alert->rule_id, 'kpi_code' => $alert->kpi_code->value, 'severity' => $alert->severity, 'observed_value' => (float) $alert->observed_value,
                'threshold_value' => (float) $alert->threshold_value, 'status' => $alert->status, 'channels' => $alert->dispatched_channels_json, 'triggered_at' => $alert->triggered_at->toIso8601String(),
                'deep_link' => "fatturafacile://kpi/{$alert->kpi_code->role()->value}/{$alert->kpi_code->value}?drill=1",
            ])->all(),
        ]);
    }

    #[OA\Post(path: '/api/v1/kpi-dashboards/alerts', operationId: 'configureKpiThresholdAlert', description: 'Creates or updates an alert rule such as cash runway below 3 months: warning and critical boundaries, notification channels (in-app, push, email) and the cooldown that deduplicates repeated breaches.', summary: 'Configure a KPI threshold alert', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['KPI Dashboards'], responses: [new OA\Response(response: 200, description: 'The rule'), new OA\Response(response: 422, description: 'Invalid rule')])]
    public function configureAlert(ConfigureThresholdAlertRequest $request, ConfigureKpiThresholdAlertHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(function () use ($data, $handler): JsonResponse {
            $rule = $handler->handle(new ConfigureKpiThresholdAlertCommand(
                $this->tenantId(),
                KpiCode::from($data['kpi_code']),
                $data['operator'],
                isset($data['warning_value']) ? (float) $data['warning_value'] : null,
                isset($data['critical_value']) ? (float) $data['critical_value'] : null,
                $data['channels'] ?? ['in_app'],
                (int) ($data['cooldown_minutes'] ?? 1440),
                (bool) ($data['is_active'] ?? true),
                $data['rule_id'] ?? null,
            ));

            return $this->successResponse(['id' => $rule->id, 'kpi_code' => $rule->kpi_code->value, 'operator' => $rule->operator, 'warning_value' => $rule->warning_value, 'critical_value' => $rule->critical_value, 'channels' => $rule->channels_json, 'cooldown_minutes' => $rule->cooldown_minutes, 'is_active' => $rule->is_active], 'Threshold saved');
        });
    }

    #[OA\Post(path: '/api/v1/kpi-dashboards/alerts/{id}/acknowledge', operationId: 'acknowledgeKpiThresholdAlert', description: 'Marks a threshold breach as acknowledged.', summary: 'Acknowledge a KPI alert', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['KPI Dashboards'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Acknowledged'), new OA\Response(response: 404, description: 'Unknown alert')])]
    public function acknowledgeAlert(string $id, RefreshCubesRequest $request): JsonResponse
    {
        $alert = KpiThresholdAlertLog::query()->where('tenant_id', $this->tenantId())->whereKey($id)->first();

        if (! $alert instanceof KpiThresholdAlertLog) {
            return $this->problemDetailsResponse('Not Found', 'Unknown alert.', Response::HTTP_NOT_FOUND);
        }

        $alert->update(['status' => 'acknowledged', 'acknowledged_at' => now(), 'acknowledged_by' => $this->user()?->getKey()]);

        return $this->successResponse(['id' => $alert->id, 'status' => $alert->status], 'Alert acknowledged');
    }

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }

    private function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * Maps the module exceptions to problem details.
     *
     * @template T
     *
     * @param  Closure(): T  $action
     * @return T|JsonResponse
     */
    private function guarded(Closure $action): mixed
    {
        try {
            return $action();
        } catch (KpiNotFoundException $exception) {
            return $this->problemDetailsResponse('Not Found', $exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (KpiAccessDeniedException $exception) {
            return $this->problemDetailsResponse('Forbidden KPI Access', $exception->getMessage(), Response::HTTP_FORBIDDEN);
        } catch (KpiDashboardException $exception) {
            return $this->problemDetailsResponse('KPI Dashboard Rule Violation', $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
