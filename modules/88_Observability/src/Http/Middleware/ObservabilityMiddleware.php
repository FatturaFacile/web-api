<?php

declare(strict_types=1);

namespace Modules\Observability\Http\Middleware;

use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Observability\Metrics\MetricCatalog;
use Modules\Observability\Metrics\MetricsRegistry;
use Modules\Observability\Profiler\ProfilerSettings;
use Modules\Observability\Profiler\QueryProfiler;
use Modules\Observability\Profiler\RequestProfile;
use Modules\Observability\Tracing\Span;
use Modules\Observability\Tracing\SpanKind;
use Modules\Observability\Tracing\TraceContext;
use Modules\Observability\Tracing\Tracer;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Global middleware of the observability stack: continues the W3C trace of the caller (traceparent / tracestate) or starts a new one, answers with
 * `X-Trace-Id` / `traceresponse`, and, after the response is sent, records the HTTP metrics, the tenant consumption, the endpoint sample, the
 * profiler captures and exports the spans. Nothing here can fail the request.
 */
final class ObservabilityMiddleware
{
    private const string SPAN_ATTRIBUTE = 'observability.span';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->active($request)) {
            return $next($request);
        }

        try {
            $tracer = app(Tracer::class);
            $span = $tracer->startSpan(
                $request->method().' '.$this->templateOf($request),
                SpanKind::Server,
                [
                    'http.request.method' => $request->method(),
                    'url.path' => $this->scrubPath($request->path()),
                    'url.scheme' => $request->getScheme(),
                    'server.address' => $request->getHost(),
                    'user_agent.original' => Str::limit((string) $request->userAgent(), 200, ''),
                ],
                TraceContext::fromHeaders($request->header('traceparent'), $request->header('tracestate')),
            );
            $request->attributes->set(self::SPAN_ATTRIBUTE, $span);

            $profile = app(RequestProfile::class);
            $profile->traceId = $span->traceId;
            $profile->originType = 'route';

            // Warms the cached settings before the query listener needs the slow-query threshold.
            if (config('observability.profiler.capture_enabled', true)) {
                app(ProfilerSettings::class)->all();
            }
        } catch (Throwable) {
            return $next($request);
        }

        $response = $next($request);

        try {
            $response->headers->set('X-Trace-Id', $span->traceId);
            $response->headers->set('traceresponse', $span->context()->toTraceparent());
        } catch (Throwable) {
            // header only
        }

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        $span = $request->attributes->get(self::SPAN_ATTRIBUTE);

        if (! $span instanceof Span) {
            return;
        }

        try {
            $tracer = app(Tracer::class);
            $profile = app(RequestProfile::class);
            $registry = app(MetricsRegistry::class);
            $tenantId = app(TenantContext::class)->getTenantId();
            $template = $this->templateOf($request);
            $status = $response->getStatusCode();
            $profile->origin = $request->method().' '.$template;

            $span->name = $request->method().' '.$template;
            $span->setAttributes([
                'http.route' => $template,
                'http.response.status_code' => $status,
                'db.query.count' => $profile->queryCount,
                'db.query.time_ms' => round($profile->dbMs, 2),
                'tenant.id' => $tenantId,
            ]);

            if ($status >= 500) {
                $span->setStatus(Span::STATUS_ERROR, 'HTTP '.$status);
            }

            $tracer->endSpan($span);
            $seconds = $span->durationSeconds();

            $registry->observe(MetricCatalog::HTTP_DURATION, $seconds, ['method' => $request->method(), 'route' => $template, 'status_class' => intdiv($status, 100).'xx']);
            $registry->increment(MetricCatalog::HTTP_REQUESTS, ['method' => $request->method(), 'route' => $template, 'status' => (string) $status]);

            if ($tenantId !== null) {
                $registry->incrementForTenant(MetricCatalog::TENANT_CONSUMPTION, $tenantId, ['resource' => 'api_calls']);
            }

            $this->sample($request, $template, $status, $seconds * 1000, $profile, $tenantId);
            app(QueryProfiler::class)->flush();
        } catch (Throwable) {
            // observability never fails the request
        } finally {
            app(Tracer::class)->flush();
        }
    }

    private function active(Request $request): bool
    {
        if (! config('observability.enabled', true)) {
            return false;
        }

        $path = $request->path();

        foreach ((array) config('observability.tracing.skip_paths', []) as $skipped) {
            if ($path === $skipped || str_starts_with($path, $skipped.'/')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Route template ("api/v1/invoices/{id}"), never the concrete URL: bounded cardinality and no identifiers in the labels.
     */
    private function templateOf(Request $request): string
    {
        // No route is bound for an unmatched URL (404), although the signature of route() promises one.
        $uri = optional($request->route())->uri();

        return is_string($uri) ? '/'.ltrim($uri, '/') : 'unmatched';
    }

    private function scrubPath(string $path): string
    {
        return (string) preg_replace(['/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '/\/\d+(?=\/|$)/'], ['{uuid}', '/{id}'], '/'.$path);
    }

    /**
     * Stores the request in the endpoint samples: every slow request and a configurable ratio of the others.
     */
    private function sample(Request $request, string $template, int $status, float $durationMs, RequestProfile $profile, ?string $tenantId): void
    {
        if (! config('observability.profiler.capture_enabled', true)) {
            return;
        }

        $slow = $durationMs >= app(ProfilerSettings::class)->all()['slow_query_threshold_ms'];
        $ratio = (float) config('observability.profiler.endpoint_sample_ratio', 0.25);

        if (! $slow && ($ratio <= 0 || ($ratio < 1 && random_int(0, 1_000_000) / 1_000_000 >= $ratio))) {
            return;
        }

        DB::table('observability_endpoint_samples')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'method' => $request->method(),
            'route' => Str::limit($template, 190, ''),
            'status_code' => $status,
            'duration_ms' => round($durationMs, 3),
            'db_query_count' => $profile->queryCount,
            'db_time_ms' => round($profile->dbMs, 3),
            'trace_id' => $profile->traceId,
            'captured_at' => now(),
        ]);
    }
}
