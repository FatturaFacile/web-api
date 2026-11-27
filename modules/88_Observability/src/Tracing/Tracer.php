<?php

declare(strict_types=1);

namespace Modules\Observability\Tracing;

use Modules\Observability\Privacy\PiiScrubber;
use Throwable;

/**
 * Minimal request-scoped tracer: keeps the stack of the active spans, applies the sampling decision of the root, scrubs every span when it ends
 * and hands the finished ones to the exporter. Observability never breaks the application: exporter failures are swallowed.
 */
final class Tracer
{
    /** @var list<Span> */
    private array $active = [];

    /** @var list<Span> */
    private array $finished = [];

    public function __construct(
        private readonly SpanExporter $exporter,
        private readonly PiiScrubber $scrubber,
        private readonly float $sampleRatio = 1.0,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  TraceContext|null  $remoteParent  Context received from a header or a queue payload; otherwise the active span is the parent.
     */
    public function startSpan(string $name, SpanKind $kind = SpanKind::Internal, array $attributes = [], ?TraceContext $remoteParent = null): Span
    {
        $parent = $remoteParent ?? $this->currentContext();

        $span = new Span(
            name: $name,
            kind: $kind,
            traceId: $parent->traceId ?? TraceContext::newTraceId(),
            spanId: TraceContext::newSpanId(),
            parentSpanId: $parent?->spanId,
            sampled: $parent !== null ? $parent->sampled : $this->shouldSample(),
            traceState: $parent?->traceState,
            startNanos: (int) (microtime(true) * 1_000_000_000),
            startMonotonic: hrtime(true),
        );
        $span->setAttributes($attributes);
        $this->active[] = $span;

        return $span;
    }

    public function currentSpan(): ?Span
    {
        return $this->active === [] ? null : $this->active[array_key_last($this->active)];
    }

    public function currentContext(): ?TraceContext
    {
        return $this->currentSpan()?->context();
    }

    public function endSpan(Span $span, ?Throwable $error = null): void
    {
        if ($span->isEnded()) {
            return;
        }

        if ($error !== null) {
            $span->recordException($error);
        }

        $span->end();

        // The span and the children that were left open above it leave the stack.
        $position = array_search($span, $this->active, true);

        if ($position !== false) {
            foreach (array_slice($this->active, $position + 1) as $orphan) {
                $orphan->end();
                $this->finish($orphan);
            }

            $this->active = array_slice($this->active, 0, $position);
        }

        $this->finish($span);
    }

    public function isIdle(): bool
    {
        return $this->active === [];
    }

    /**
     * Exports the finished spans (call it after the response is sent, or when a job ends).
     */
    public function flush(): void
    {
        if ($this->finished === []) {
            return;
        }

        $spans = $this->finished;
        $this->finished = [];

        try {
            $this->exporter->export($spans);
        } catch (Throwable) {
            // A collector outage must never fail a request.
        }
    }

    public function reset(): void
    {
        $this->active = [];
        $this->finished = [];
    }

    private function finish(Span $span): void
    {
        $this->scrub($span);

        if ($span->sampled) {
            $this->finished[] = $span;
        }
    }

    private function scrub(Span $span): void
    {
        $span->name = $this->scrubber->scrubString($span->name);
        $span->statusMessage = $span->statusMessage !== null ? $this->scrubber->scrubString($span->statusMessage) : null;

        /** @var array<string, scalar|null> $attributes */
        $attributes = $this->scrubber->scrubArray($span->attributes());
        $span->replaceAttributes($attributes);

        $events = [];

        foreach ($span->events() as $event) {
            /** @var array<string, scalar|null> $eventAttributes */
            $eventAttributes = $this->scrubber->scrubArray($event['attributes']);
            $events[] = ['name' => $event['name'], 'time' => $event['time'], 'attributes' => $eventAttributes];
        }

        $span->replaceEvents($events);
    }

    private function shouldSample(): bool
    {
        if ($this->sampleRatio >= 1.0) {
            return true;
        }

        if ($this->sampleRatio <= 0.0) {
            return false;
        }

        return random_int(0, 1_000_000) / 1_000_000 < $this->sampleRatio;
    }
}
