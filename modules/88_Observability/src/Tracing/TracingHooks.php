<?php

declare(strict_types=1);

namespace Modules\Observability\Tracing;

use App\Services\Tenancy\TenantContext;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Observability\Metrics\MetricCatalog;
use Modules\Observability\Metrics\MetricsRegistry;
use Modules\Observability\Profiler\QueryProfiler;
use Modules\Observability\Profiler\RequestProfile;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use WeakMap;

/**
 * Wires the trace context through the asynchronous boundaries of the application: queued jobs (and with them queued listeners and domain events)
 * carry traceparent / tracestate in their payload, outgoing HTTP calls send them to the other service, console commands get a span of their own.
 * Every hook is guarded: observability never fails the work it observes.
 */
final class TracingHooks
{
    /** @var WeakMap<Job, array{span: Span, queue: string, name: string, tenant: string|null}>|null */
    private static ?WeakMap $jobs = null;

    private static ?Span $commandSpan = null;

    private static bool $payloadHookRegistered = false;

    public function registerQueue(): void
    {
        // The payload hook is static state of the queue component and resolves everything lazily: one registration per process is enough.
        if (! self::$payloadHookRegistered) {
            self::$payloadHookRegistered = true;
            $this->registerPayloadHook();
        }

        Event::listen(JobProcessing::class, fn (JobProcessing $event) => $this->jobStarted($event));
        Event::listen(JobProcessed::class, fn (JobProcessed $event) => $this->jobFinished($event->job, $event->connectionName, null));
        Event::listen(JobExceptionOccurred::class, fn (JobExceptionOccurred $event) => $this->jobFinished($event->job, $event->connectionName, $event->exception));
        Event::listen(JobFailed::class, fn (JobFailed $event) => $this->jobFinished($event->job, $event->connectionName, $event->exception));
    }

    private function registerPayloadHook(): void
    {
        Queue::createPayloadUsing(static function (string $connection, ?string $queue, array $payload): array {
            $extra = ['obs_enqueued_at' => microtime(true)];

            try {
                $context = app(Tracer::class)->currentContext();

                if ($context !== null) {
                    $extra['traceparent'] = $context->toTraceparent();

                    if ($context->traceState !== null) {
                        $extra['tracestate'] = $context->traceState;
                    }
                }

                $tenantId = app(TenantContext::class)->getTenantId();

                if ($tenantId !== null) {
                    $extra['obs_tenant_id'] = $tenantId;
                }
            } catch (Throwable) {
                // The job is queued without correlation.
            }

            return $extra;
        });
    }

    public function registerHttpClient(): void
    {
        if (! config('observability.tracing.propagate_outgoing', true)) {
            return;
        }

        Http::globalMiddleware(static fn (callable $handler): callable => static function (RequestInterface $request, array $options) use ($handler) {
            if (($options['observability_skip'] ?? false) === true) {
                return $handler($request, $options);
            }

            try {
                $hosts = config('observability.tracing.propagate_hosts');
                $host = $request->getUri()->getHost();

                if (is_array($hosts) && ! in_array($host, $hosts, true)) {
                    return $handler($request, $options);
                }

                $tracer = app(Tracer::class);
                $span = $tracer->startSpan('HTTP '.$request->getMethod(), SpanKind::Client, [
                    'http.request.method' => $request->getMethod(),
                    'server.address' => $host,
                    'url.full' => (string) (new Uri((string) $request->getUri()))->withQuery('')->withUserInfo(''),
                ]);
                $context = $span->context();
                $request = $request->withHeader('traceparent', $context->toTraceparent());

                if ($context->traceState !== null) {
                    $request = $request->withHeader('tracestate', $context->traceState);
                }
            } catch (Throwable) {
                return $handler($request, $options);
            }

            return $handler($request, $options)->then(
                static function (ResponseInterface $response) use ($tracer, $span): ResponseInterface {
                    $span->setAttribute('http.response.status_code', $response->getStatusCode());

                    if ($response->getStatusCode() >= 500) {
                        $span->setStatus(Span::STATUS_ERROR, 'HTTP '.$response->getStatusCode());
                    }

                    $tracer->endSpan($span);

                    return $response;
                },
                static function (mixed $reason) use ($tracer, $span) {
                    $tracer->endSpan($span, $reason instanceof Throwable ? $reason : null);

                    return Create::rejectionFor($reason);
                },
            );
        });
    }

    public function registerConsole(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            $name = (string) $event->command;

            if ($name === '' || in_array($name, (array) config('observability.tracing.skip_commands', []), true)) {
                return;
            }

            try {
                self::$commandSpan = app(Tracer::class)->startSpan('artisan '.$name, SpanKind::Internal, ['process.command' => $name]);
                $profile = app(RequestProfile::class);
                $profile->traceId = self::$commandSpan->traceId;
                $profile->originType = 'console';
                $profile->origin = 'artisan '.$name;
            } catch (Throwable) {
                self::$commandSpan = null;
            }
        });

        Event::listen(CommandFinished::class, function (CommandFinished $event): void {
            $span = self::$commandSpan;
            self::$commandSpan = null;

            if ($span === null) {
                return;
            }

            try {
                $span->setAttribute('process.exit_code', $event->exitCode);

                if ($event->exitCode !== 0) {
                    $span->setStatus(Span::STATUS_ERROR, 'exit '.$event->exitCode);
                }

                app(Tracer::class)->endSpan($span);
                $this->finishUnitOfWork();
            } catch (Throwable) {
                // see class doc
            }
        });
    }

    /**
     * Persists the profiler captures and exports the finished spans of a command (called when the application terminates).
     */
    public function finishUnitOfWork(): void
    {
        try {
            if (! app()->runningInConsole()) {
                return;
            }

            app(QueryProfiler::class)->flush();
            app(Tracer::class)->flush();
        } catch (Throwable) {
            // see class doc
        }
    }

    private function jobStarted(JobProcessing $event): void
    {
        try {
            $payload = $event->job->payload();
            $tracer = app(Tracer::class);
            $name = $this->jobName($event->job);
            $queue = $event->job->getQueue();
            $parent = TraceContext::fromHeaders(is_string($payload['traceparent'] ?? null) ? $payload['traceparent'] : null, is_string($payload['tracestate'] ?? null) ? $payload['tracestate'] : null);

            $span = $tracer->startSpan('queue.process '.$name, SpanKind::Consumer, [
                'messaging.system' => 'laravel-queue',
                'messaging.destination.name' => $queue,
                'messaging.operation.type' => 'process',
                'messaging.message.id' => $event->job->getJobId(),
                'job.name' => $name,
                'job.attempts' => $event->job->attempts(),
            ], $parent);

            $tenant = is_string($payload['obs_tenant_id'] ?? null) ? $payload['obs_tenant_id'] : null;
            $span->setAttribute('tenant.id', $tenant);
            $jobs = self::jobs();
            $jobs[$event->job] = ['span' => $span, 'queue' => (string) $queue, 'name' => $name, 'tenant' => $tenant];

            $profile = app(RequestProfile::class);
            $profile->traceId = $span->traceId;
            $profile->originType = 'job';
            $profile->origin = $name;

            if ($event->connectionName !== 'sync' && is_numeric($payload['obs_enqueued_at'] ?? null)) {
                app(MetricsRegistry::class)->observe(MetricCatalog::QUEUE_WAIT, max(0.0, microtime(true) - (float) $payload['obs_enqueued_at']), ['queue' => (string) $queue]);
            }
        } catch (Throwable) {
            // see class doc
        }
    }

    private function jobFinished(Job $job, string $connection, ?Throwable $error): void
    {
        try {
            $jobs = self::jobs();

            if (! isset($jobs[$job])) {
                return;
            }

            $entry = $jobs[$job];
            unset($jobs[$job]);
            $tracer = app(Tracer::class);
            $registry = app(MetricsRegistry::class);

            if ($error === null) {
                $entry['span']->setStatus(Span::STATUS_OK);
            }

            $tracer->endSpan($entry['span'], $error);
            $registry->increment(MetricCatalog::QUEUE_JOBS, ['queue' => $entry['queue'], 'job' => $entry['name'], 'status' => $error === null ? 'processed' : 'failed']);
            $registry->observe(MetricCatalog::QUEUE_JOB_DURATION, $entry['span']->durationSeconds(), ['queue' => $entry['queue'], 'job' => $entry['name']]);

            if ($entry['tenant'] !== null) {
                $registry->incrementForTenant(MetricCatalog::TENANT_CONSUMPTION, $entry['tenant'], ['resource' => 'queue_jobs']);
            }

            // A sync job runs inside its request: that request flushes. A real worker flushes after every job.
            if ($connection !== 'sync') {
                app(QueryProfiler::class)->flush();

                if ($tracer->isIdle()) {
                    $tracer->flush();
                }
            }
        } catch (Throwable) {
            // see class doc
        }
    }

    private function jobName(Job $job): string
    {
        return Str::before(class_basename($job->resolveName()), '@');
    }

    /**
     * @return WeakMap<Job, array{span: Span, queue: string, name: string, tenant: string|null}>
     */
    private static function jobs(): WeakMap
    {
        return self::$jobs ??= new WeakMap;
    }
}
