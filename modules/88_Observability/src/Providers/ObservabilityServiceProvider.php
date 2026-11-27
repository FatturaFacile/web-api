<?php

declare(strict_types=1);

namespace Modules\Observability\Providers;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Modules\Observability\Connectors\StreamTlsCertificateInspector;
use Modules\Observability\Connectors\TlsCertificateInspector;
use Modules\Observability\Console\ProbeConnectorsCommand;
use Modules\Observability\Console\PruneObservabilityDataCommand;
use Modules\Observability\Metrics\MetricsRegistry;
use Modules\Observability\Privacy\PiiScrubber;
use Modules\Observability\Privacy\SqlSanitizer;
use Modules\Observability\Profiler\QueryProfiler;
use Modules\Observability\Profiler\RequestProfile;
use Modules\Observability\Tracing\NullSpanExporter;
use Modules\Observability\Tracing\OtlpHttpJsonSpanExporter;
use Modules\Observability\Tracing\SpanExporter;
use Modules\Observability\Tracing\Tracer;
use Modules\Observability\Tracing\TracingHooks;

/**
 * Mod 202 — enterprise observability (Issue #448): W3C trace context and OTLP export, Prometheus metrics, SQL profiler with index advisor and
 * N+1 detection, PII scrubbing of logs / traces / SQL, and the synthetic probes of the CH/IT institutional connectors.
 */
final class ObservabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PiiScrubber::class);
        $this->app->singleton(SqlSanitizer::class);
        $this->app->bind(TlsCertificateInspector::class, StreamTlsCertificateInspector::class);

        $this->app->singleton(MetricsRegistry::class, function (): MetricsRegistry {
            $store = config('observability.metrics.store');
            /** @var Repository $repository */
            $repository = is_string($store) && $store !== '' ? Cache::store($store) : Cache::store();

            return new MetricsRegistry($repository, (int) config('observability.metrics.max_tenant_series', 500));
        });

        $this->app->singleton(SpanExporter::class, function (): SpanExporter {
            $endpoint = (string) (config('observability.tracing.traces_endpoint') ?: config('observability.tracing.endpoint') ?: '');

            if (! config('observability.tracing.enabled', true) || config('observability.tracing.exporter') !== 'otlp' || $endpoint === '') {
                return new NullSpanExporter;
            }

            return new OtlpHttpJsonSpanExporter(
                $endpoint,
                OtlpHttpJsonSpanExporter::parseHeaders((string) config('observability.tracing.headers')),
                [
                    'service.name' => (string) config('observability.service.name'),
                    'service.version' => (string) config('observability.service.version'),
                    'deployment.environment.name' => (string) config('observability.service.environment'),
                ],
                (float) config('observability.tracing.timeout_seconds', 2),
                (int) config('observability.tracing.max_batch_size', 200),
            );
        });

        // Per request / job state: scoped, so a long-lived worker (queue, Octane) starts every unit of work clean.
        $this->app->scoped(Tracer::class, fn ($app): Tracer => new Tracer($app->make(SpanExporter::class), $app->make(PiiScrubber::class), (float) config('observability.tracing.sampler_ratio', 1.0)));
        $this->app->scoped(RequestProfile::class);
        $this->app->scoped(QueryProfiler::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([ProbeConnectorsCommand::class, PruneObservabilityDataCommand::class]);
        }

        if (! config('observability.enabled', true)) {
            return;
        }

        DB::listen(function (QueryExecuted $query): void {
            $this->app->make(QueryProfiler::class)->handle($query);
        });

        $hooks = new TracingHooks;
        $hooks->registerQueue();
        $hooks->registerHttpClient();
        $hooks->registerConsole();

        // Console commands: persist the profiler captures and export the spans when the application terminates.
        $this->app->terminating(function () use ($hooks): void {
            $hooks->finishUnitOfWork();
        });
    }
}
