<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Modules\Manufacturing\Http\Controllers\Concerns\InteractsWithManufacturing;
use Modules\Manufacturing\Http\Requests\IngestEdgeTelemetryRequest;
use Modules\Manufacturing\Http\Requests\StoreEdgeGatewayRequest;
use Modules\Manufacturing\Models\MfgEdgeGateway;
use Modules\Manufacturing\Services\EdgeTelemetryService;
use OpenApi\Attributes as OA;

/**
 * Edge gateways and their machine state telemetry.
 */
final class EdgeTelemetryController extends Controller
{
    use InteractsWithManufacturing;

    public function __construct(private readonly EdgeTelemetryService $telemetry, private readonly TenantContext $context) {}

    /**
     * Registered gateways.
     */
    #[OA\Get(path: '/api/v1/manufacturing/edge/gateways', operationId: 'listMfgEdgeGateways', description: 'Edge gateways of the active company (secrets are never returned).', summary: 'List edge gateways', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Edge'],
        responses: [new OA\Response(response: 200, description: 'Gateways'), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401)])]
    public function gateways(): JsonResponse
    {
        return response()->json(['data' => MfgEdgeGateway::query()->where('tenant_id', $this->tenantId())->orderBy('name')->get(['id', 'name', 'is_active', 'last_seen_at'])]);
    }

    /**
     * Registers a gateway and returns its secret once.
     */
    #[OA\Post(path: '/api/v1/manufacturing/edge/gateways', operationId: 'createMfgEdgeGateway', description: 'Registers an edge gateway. The shared HMAC secret is returned only in this response.', summary: 'Register edge gateway', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Edge'],
        responses: [new OA\Response(response: 201, description: 'Gateway with its secret'), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function storeGateway(StoreEdgeGatewayRequest $request): JsonResponse
    {
        $secret = Str::random(64);
        $gateway = MfgEdgeGateway::create(['tenant_id' => $this->tenantId(), 'name' => (string) $request->validated('name'), 'secret' => $secret, 'is_active' => true]);

        return response()->json(['data' => ['id' => $gateway->id, 'name' => $gateway->name, 'secret' => $secret]], 201);
    }

    /**
     * Ingests a signed batch of machine state events.
     */
    #[OA\Post(path: '/api/v1/manufacturing/edge/telemetry', operationId: 'ingestMfgEdgeTelemetry', description: 'Batch of machine state events (RUNNING, SETUP, IDLE, FAULT, MAINTENANCE). Authenticated by X-Gateway-Id and the hex HMAC-SHA256 of the raw body in X-Signature. Each event is idempotent by event_uuid.', summary: 'Ingest edge telemetry', tags: ['Manufacturing Edge'],
        responses: [new OA\Response(response: 200, description: 'Counts of accepted, duplicate and rejected events'), new OA\Response(response: 401, description: 'Unknown gateway or invalid signature'), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function ingest(IngestEdgeTelemetryRequest $request): JsonResponse
    {
        $gateway = $request->gateway();
        $tenant = Tenant::query()->findOrFail($gateway->tenant_id);
        /** @var list<array{event_uuid: string, machine_id: string, state: string, occurred_at: string, payload?: array<string, mixed>|null}> $events */
        $events = array_values($request->validated('events'));

        $result = $this->context->runWithTenant($tenant, fn (): array => $this->telemetry->ingest($gateway, $events));

        return response()->json(['data' => $result]);
    }
}
