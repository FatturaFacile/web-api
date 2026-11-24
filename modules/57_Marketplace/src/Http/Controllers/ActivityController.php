<?php

declare(strict_types=1);

namespace Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Marketplace\Exceptions\ConnectorRateLimitedException;
use Modules\Marketplace\Http\Controllers\Concerns\InteractsWithMarketplace;
use Modules\Marketplace\Http\Requests\SyncRequest;
use Modules\Marketplace\Http\Resources\HealthCheckResource;
use Modules\Marketplace\Http\Resources\InstallationResource;
use Modules\Marketplace\Http\Resources\SyncRunResource;
use Modules\Marketplace\Models\MarketplaceHealthCheck;
use Modules\Marketplace\Models\MarketplaceInstallation;
use Modules\Marketplace\Models\MarketplaceSyncRun;
use Modules\Marketplace\Services\HealthService;
use Modules\Marketplace\Services\SyncService;
use OpenApi\Attributes as OA;

/**
 * What installations did: health checks, synchronisations and the sync run log, plus the overview of the module.
 */
final class ActivityController extends Controller
{
    use InteractsWithMarketplace;

    public function __construct(private readonly HealthService $health, private readonly SyncService $sync) {}

    #[OA\Get(path: '/api/v1/marketplace/installations/{id}/health-checks', operationId: 'listMarketplaceHealthChecks', description: 'The latest health checks of an installation, newest first (the last 50 are kept).', summary: 'List health checks', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketplace'], parameters: [new OA\Parameter(ref: '#/components/parameters/MarketplaceId')], responses: [
        new OA\Response(response: 200, description: 'Health checks', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MarketplaceHealthCheck'))])),
        new OA\Response(ref: '#/components/responses/MarketplaceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/MarketplaceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/MarketplaceNotFound', response: 404),
    ])]
    public function healthChecks(string $id): AnonymousResourceCollection
    {
        $installation = MarketplaceInstallation::query()->findOrFail($id);
        $this->authorize('view', $installation);

        return HealthCheckResource::collection(MarketplaceHealthCheck::query()->where('installation_id', $installation->id)->orderByDesc('checked_at')->paginate($this->perPage()));
    }

    #[OA\Post(path: '/api/v1/marketplace/installations/{id}/health-checks', operationId: 'runMarketplaceHealthCheck', description: 'Checks now that the external system answers and accepts the credentials. The installation must be configured. The result is stored and summarised on the installation.', summary: 'Run a health check', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketplace'], parameters: [new OA\Parameter(ref: '#/components/parameters/MarketplaceId')], responses: [
        new OA\Response(response: 201, description: 'Result of the check', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MarketplaceHealthCheck')])),
        new OA\Response(ref: '#/components/responses/MarketplaceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/MarketplaceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/MarketplaceNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/MarketplaceUnprocessable', response: 422),
    ])]
    public function runHealthCheck(string $id): JsonResponse
    {
        $installation = MarketplaceInstallation::query()->findOrFail($id);
        $this->authorize('update', $installation);

        return (new HealthCheckResource($this->health->check($installation)))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/marketplace/installations/{id}/sync-runs', operationId: 'listMarketplaceInstallationSyncRuns', description: 'The sync run log of an installation, newest first. Filter: status.', summary: 'List the runs of an installation', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketplace'], parameters: [new OA\Parameter(ref: '#/components/parameters/MarketplaceId')], responses: [
        new OA\Response(response: 200, description: 'Runs', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MarketplaceSyncRun'))])),
        new OA\Response(ref: '#/components/responses/MarketplaceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/MarketplaceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/MarketplaceNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/MarketplaceUnprocessable', response: 422),
    ])]
    public function installationRuns(Request $request, string $id): AnonymousResourceCollection
    {
        $installation = MarketplaceInstallation::query()->findOrFail($id);
        $this->authorize('view', $installation);
        $filters = $request->validate(['status' => ['nullable', Rule::in(MarketplaceSyncRun::Statuses)]]);

        return SyncRunResource::collection(
            MarketplaceSyncRun::query()->where('installation_id', $installation->id)
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->orderByDesc('started_at')->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/marketplace/installations/{id}/sync-runs', operationId: 'runMarketplaceSync', description: 'Synchronises one resource of an enabled installation. The installation has a calls-a-minute rate limit: beyond it the run is recorded as rate_limited and the request is refused with 429 and a Retry-After header, without calling the external system. One run per resource at a time. A failure of the external system ends the run as failed with a message that never contains a credential, and the response is still 201 with that run.', summary: 'Run a synchronisation', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MarketplaceSyncRequest')), tags: ['Marketplace'], parameters: [new OA\Parameter(ref: '#/components/parameters/MarketplaceId')], responses: [
        new OA\Response(response: 201, description: 'The run', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MarketplaceSyncRun')])),
        new OA\Response(ref: '#/components/responses/MarketplaceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/MarketplaceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/MarketplaceNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/MarketplaceUnprocessable', response: 422),
        new OA\Response(response: 429, description: 'The rate limit of the installation is used up', headers: [new OA\Header(header: 'Retry-After', description: 'Seconds to wait', schema: new OA\Schema(type: 'integer'))], content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function runSync(SyncRequest $request, string $id): JsonResponse
    {
        $installation = MarketplaceInstallation::query()->findOrFail($id);
        $this->authorize('update', $installation);

        try {
            $run = $this->sync->run($installation, (string) $request->validated('resource'), $this->currentUser());
        } catch (ConnectorRateLimitedException $exception) {
            abort(429, $exception->getMessage(), ['Retry-After' => (string) $exception->retryAfterSeconds]);
        }

        return (new SyncRunResource($run))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/marketplace/sync-runs', operationId: 'listMarketplaceSyncRuns', description: 'The sync run log of every installation of the company, newest first. Filters: status, installation_id.', summary: 'List the sync run log', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketplace'], responses: [
        new OA\Response(response: 200, description: 'Runs', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MarketplaceSyncRun'))])),
        new OA\Response(ref: '#/components/responses/MarketplaceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/MarketplaceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/MarketplaceUnprocessable', response: 422),
    ])]
    public function runs(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', MarketplaceSyncRun::class);
        $filters = $request->validate(['status' => ['nullable', Rule::in(MarketplaceSyncRun::Statuses)], 'installation_id' => ['nullable', 'uuid']]);

        return SyncRunResource::collection(
            MarketplaceSyncRun::query()->with('installation:id,name')
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->when(isset($filters['installation_id']), static fn ($query) => $query->where('installation_id', $filters['installation_id']))
                ->orderByDesc('started_at')->paginate($this->perPage()),
        );
    }

    #[OA\Get(path: '/api/v1/marketplace/overview', operationId: 'showMarketplaceOverview', description: 'Installations by status, the ones that are not healthy, the latest runs that failed or were rate limited, and connectors with a newer version available.', summary: 'Marketplace overview', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketplace'], responses: [
        new OA\Response(response: 200, description: 'Overview', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MarketplaceOverview')])),
        new OA\Response(ref: '#/components/responses/MarketplaceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/MarketplaceForbidden', response: 403),
    ])]
    public function overview(): JsonResponse
    {
        $this->authorize('viewAny', MarketplaceInstallation::class);
        $byStatus = MarketplaceInstallation::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $installations = MarketplaceInstallation::query()->orderBy('name')->get();

        return response()->json(['data' => [
            'installations' => (int) $installations->count(),
            'by_status' => ['pending_configuration' => (int) ($byStatus['pending_configuration'] ?? 0), 'disabled' => (int) ($byStatus['disabled'] ?? 0), 'enabled' => (int) ($byStatus['enabled'] ?? 0)],
            'unhealthy' => InstallationResource::collection($installations->filter(static fn (MarketplaceInstallation $installation): bool => $installation->last_health_status === 'unhealthy' || $installation->last_health_status === 'degraded')->values())->resolve(),
            'updates_available' => InstallationResource::collection($installations->filter(static fn (MarketplaceInstallation $installation): bool => (bool) (new InstallationResource($installation))->toArray(request())['update_available'])->values())->resolve(),
            'recent_problem_runs' => SyncRunResource::collection(MarketplaceSyncRun::query()->with('installation:id,name')->whereIn('status', [MarketplaceSyncRun::Failed, MarketplaceSyncRun::RateLimited])->orderByDesc('started_at')->limit(5)->get())->resolve(),
        ]]);
    }
}
