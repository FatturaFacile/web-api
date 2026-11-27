<?php

declare(strict_types=1);

namespace Modules\Observability\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Observability\Application\Commands\RunConnectorProbesCommand;
use Modules\Observability\Application\Commands\RunConnectorProbesHandler;
use Modules\Observability\Application\Commands\SetConnectorMaintenanceCommand;
use Modules\Observability\Application\Commands\SetConnectorMaintenanceHandler;
use Modules\Observability\Connectors\ConnectorCatalog;
use Modules\Observability\Connectors\ConnectorStatusService;
use Modules\Observability\Http\Requests\NoInputRequest;
use Modules\Observability\Http\Requests\ProbeConnectorsRequest;
use Modules\Observability\Http\Requests\SetMaintenanceRequest;
use Modules\Observability\Http\Requests\WindowRequest;
use Modules\Observability\Http\Resources\ConnectorResource;
use OpenApi\Attributes as OA;

/**
 * Status of the CH/IT institutional connectors: the administrator view (checks, errors, TLS, probes on demand, maintenance mode) and the
 * tenant-facing summary that feeds the status banner and the status page.
 */
final class ConnectorController extends Controller
{
    use ApiResponse;

    private const array SECURITY = [['bearerAuth' => []]];

    private const array TENANT_SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/admin/observability/connectors', operationId: 'observabilityConnectorsIndex', summary: 'Status of every institutional connector with latency, uptime and TLS detail', security: self::SECURITY, tags: ['Observability'], responses: [new OA\Response(response: 200, description: 'Connectors')])]
    public function index(NoInputRequest $request, ConnectorStatusService $statuses): JsonResponse
    {
        return $this->successResponse(ConnectorResource::collection($statuses->all(true)), __('observability.messages.listed'));
    }

    #[OA\Get(path: '/api/v1/admin/observability/connectors/{key}/history', operationId: 'observabilityConnectorHistory', summary: 'Individual probe results of a connector', security: self::SECURITY, tags: ['Observability'], responses: [new OA\Response(response: 200, description: 'History'), new OA\Response(response: 404, description: 'Unknown connector')])]
    public function history(string $key, WindowRequest $request, ConnectorCatalog $catalog, ConnectorStatusService $statuses): JsonResponse
    {
        if (! $catalog->has($key)) {
            return $this->problemDetailsResponse(__('observability.errors.unknown_connector_title'), __('observability.errors.unknown_connector'), 404);
        }

        return $this->successResponse([
            'connector' => new ConnectorResource($statuses->view($key, true)),
            'checks' => $statuses->checks($key, $request->hours()),
        ], __('observability.messages.loaded'));
    }

    #[OA\Post(path: '/api/v1/admin/observability/connectors/probe', operationId: 'observabilityConnectorsProbe', summary: 'Probes one or all connectors now', security: self::SECURITY, tags: ['Observability'], responses: [new OA\Response(response: 200, description: 'Fresh state')])]
    public function probe(ProbeConnectorsRequest $request, RunConnectorProbesHandler $handler): JsonResponse
    {
        $key = $request->validated('key');
        $connectors = $handler->handle(new RunConnectorProbesCommand(is_string($key) && $key !== '' ? $key : null));

        return $this->successResponse(ConnectorResource::collection($connectors), __('observability.messages.probed'));
    }

    #[OA\Put(path: '/api/v1/admin/observability/connectors/{key}/maintenance', operationId: 'observabilityConnectorMaintenance', summary: 'Puts a connector in or out of maintenance (announced to every tenant)', security: self::SECURITY, tags: ['Observability'], responses: [new OA\Response(response: 200, description: 'Connector'), new OA\Response(response: 404, description: 'Unknown connector')])]
    public function maintenance(string $key, SetMaintenanceRequest $request, ConnectorCatalog $catalog, SetConnectorMaintenanceHandler $handler): JsonResponse
    {
        if (! $catalog->has($key)) {
            return $this->problemDetailsResponse(__('observability.errors.unknown_connector_title'), __('observability.errors.unknown_connector'), 404);
        }

        $message = $request->validated('message');
        $connector = $handler->handle(new SetConnectorMaintenanceCommand($key, $request->boolean('maintenance'), is_string($message) && $message !== '' ? $message : null));

        return $this->successResponse(new ConnectorResource($connector), __('observability.messages.saved'));
    }

    #[OA\Get(path: '/api/v1/system/connector-status', operationId: 'observabilityConnectorSummary', summary: 'Tenant-facing summary of the institutional connectors (status, uptime, latency, hourly history)', security: self::TENANT_SECURITY, tags: ['Observability'], responses: [new OA\Response(response: 200, description: 'Summary')])]
    public function summary(NoInputRequest $request, ConnectorStatusService $statuses): JsonResponse
    {
        $summary = $statuses->summary(false);
        $summary['connectors'] = ConnectorResource::collection($summary['connectors']);

        return $this->successResponse($summary, __('observability.messages.loaded'));
    }
}
