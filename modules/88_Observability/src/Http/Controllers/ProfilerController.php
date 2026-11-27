<?php

declare(strict_types=1);

namespace Modules\Observability\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Observability\Application\Commands\UpdateProfilerSettingsCommand;
use Modules\Observability\Application\Commands\UpdateProfilerSettingsHandler;
use Modules\Observability\Application\Queries\NoisyNeighborsHandler;
use Modules\Observability\Application\Queries\NoisyNeighborsQuery;
use Modules\Observability\Application\Queries\TopSlowEndpointsHandler;
use Modules\Observability\Application\Queries\TopSlowEndpointsQuery;
use Modules\Observability\Application\Queries\TopSlowQueriesHandler;
use Modules\Observability\Application\Queries\TopSlowQueriesQuery;
use Modules\Observability\Http\Requests\NoInputRequest;
use Modules\Observability\Http\Requests\UpdateProfilerSettingsRequest;
use Modules\Observability\Http\Requests\WindowRequest;
use Modules\Observability\Http\Resources\NoisyNeighborResource;
use Modules\Observability\Http\Resources\ProfilerSettingsResource;
use Modules\Observability\Http\Resources\SlowEndpointResource;
use Modules\Observability\Http\Resources\SlowQueryResource;
use Modules\Observability\Profiler\PostgresStatStatements;
use Modules\Observability\Profiler\ProfilerSettings;
use OpenApi\Attributes as OA;

/**
 * Performance profiler and slow query inspector of the platform administrator (Mod 202, Issue #448). Routes sit behind `platform.admin`.
 */
final class ProfilerController extends Controller
{
    use ApiResponse;

    private const array SECURITY = [['bearerAuth' => []]];

    #[OA\Get(path: '/api/v1/admin/observability/profiler/slow-queries', operationId: 'observabilitySlowQueries', summary: 'Top slowest SQL statements of the window (plan, N+1 flag, suggested indexes)', security: self::SECURITY, tags: ['Observability'], responses: [new OA\Response(response: 200, description: 'Slow queries')])]
    public function slowQueries(WindowRequest $request, TopSlowQueriesHandler $handler): JsonResponse
    {
        $rows = $handler->handle(new TopSlowQueriesQuery($request->hours(), $request->limit()));

        return $this->successResponse(SlowQueryResource::collection($rows), __('observability.messages.listed'));
    }

    #[OA\Get(path: '/api/v1/admin/observability/profiler/slow-endpoints', operationId: 'observabilitySlowEndpoints', summary: 'Top slowest API endpoints of the window (p50, p95, p99, queries per request, trace)', security: self::SECURITY, tags: ['Observability'], responses: [new OA\Response(response: 200, description: 'Slow endpoints')])]
    public function slowEndpoints(WindowRequest $request, TopSlowEndpointsHandler $handler): JsonResponse
    {
        $rows = $handler->handle(new TopSlowEndpointsQuery($request->hours(), $request->limit()));

        return $this->successResponse(SlowEndpointResource::collection($rows), __('observability.messages.listed'));
    }

    #[OA\Get(path: '/api/v1/admin/observability/profiler/statements', operationId: 'observabilityStatements', summary: 'PostgreSQL pg_stat_statements report with graceful diagnostics when the extension is missing', security: self::SECURITY, tags: ['Observability'], responses: [new OA\Response(response: 200, description: 'Statements')])]
    public function statements(NoInputRequest $request, PostgresStatStatements $statements): JsonResponse
    {
        return $this->successResponse($statements->top(20), __('observability.messages.loaded'));
    }

    #[OA\Get(path: '/api/v1/admin/observability/profiler/noisy-neighbors', operationId: 'observabilityNoisyNeighbors', summary: 'Share of the database time per tenant, flagged above the alert threshold', security: self::SECURITY, tags: ['Observability'], responses: [new OA\Response(response: 200, description: 'Noisy neighbours')])]
    public function noisyNeighbors(WindowRequest $request, NoisyNeighborsHandler $handler): JsonResponse
    {
        $rows = $handler->handle(new NoisyNeighborsQuery($request->hours(1), $request->limit()));

        return $this->successResponse(NoisyNeighborResource::collection($rows), __('observability.messages.listed'));
    }

    #[OA\Get(path: '/api/v1/admin/observability/profiler/settings', operationId: 'observabilityProfilerSettingsShow', summary: 'Slow-query threshold and predictive alert thresholds', security: self::SECURITY, tags: ['Observability'], responses: [new OA\Response(response: 200, description: 'Settings')])]
    public function showSettings(NoInputRequest $request, ProfilerSettings $settings): JsonResponse
    {
        return $this->successResponse(new ProfilerSettingsResource($settings->all()), __('observability.messages.loaded'));
    }

    #[OA\Put(path: '/api/v1/admin/observability/profiler/settings', operationId: 'observabilityProfilerSettingsUpdate', summary: 'Changes the slow-query threshold and the predictive alert thresholds', security: self::SECURITY, tags: ['Observability'], responses: [new OA\Response(response: 200, description: 'Settings saved')])]
    public function updateSettings(UpdateProfilerSettingsRequest $request, UpdateProfilerSettingsHandler $handler): JsonResponse
    {
        $settings = $handler->handle(new UpdateProfilerSettingsCommand($request->validated()));

        return $this->successResponse(new ProfilerSettingsResource($settings), __('observability.messages.saved'));
    }
}
