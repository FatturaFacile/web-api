<?php

declare(strict_types=1);

namespace Modules\Observability\Tracing;

use Throwable;

/**
 * One unit of work of a trace. Attributes are scrubbed by the tracer when the span ends, so a caller can set raw values.
 */
final class Span
{
    public const string STATUS_UNSET = 'unset';

    public const string STATUS_OK = 'ok';

    public const string STATUS_ERROR = 'error';

    public ?int $endNanos = null;

    public string $status = self::STATUS_UNSET;

    public ?string $statusMessage = null;

    /** @var array<string, scalar|null> */
    private array $attributes = [];

    /** @var list<array{name: string, time: int, attributes: array<string, scalar|null>}> */
    private array $events = [];

    public function __construct(
        public string $name,
        public readonly SpanKind $kind,
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly ?string $parentSpanId,
        public readonly bool $sampled,
        public readonly ?string $traceState,
        public readonly int $startNanos,
        private readonly int $startMonotonic,
    ) {}

    public function setAttribute(string $key, mixed $value): self
    {
        if (is_scalar($value) || $value === null) {
            $this->attributes[$key] = $value;
        } elseif ($value instanceof \Stringable) {
            $this->attributes[$key] = (string) $value;
        }

        return $this;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function setAttributes(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }

        return $this;
    }

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    public function addEvent(string $name, array $attributes = []): self
    {
        $this->events[] = ['name' => $name, 'time' => $this->startNanos + (hrtime(true) - $this->startMonotonic), 'attributes' => $attributes];

        return $this;
    }

    public function recordException(Throwable $throwable): self
    {
        $this->addEvent('exception', ['exception.type' => $throwable::class, 'exception.message' => $throwable->getMessage()]);

        return $this->setStatus(self::STATUS_ERROR, $throwable::class);
    }

    public function setStatus(string $status, ?string $message = null): self
    {
        $this->status = $status;
        $this->statusMessage = $message;

        return $this;
    }

    public function isEnded(): bool
    {
        return $this->endNanos !== null;
    }

    public function durationSeconds(): float
    {
        return (($this->endNanos ?? ($this->startNanos + (hrtime(true) - $this->startMonotonic))) - $this->startNanos) / 1_000_000_000;
    }

    public function end(): void
    {
        $this->endNanos ??= $this->startNanos + (hrtime(true) - $this->startMonotonic);
    }

    /**
     * @return array<string, scalar|null>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    public function replaceAttributes(array $attributes): void
    {
        $this->attributes = $attributes;
    }

    /**
     * @return list<array{name: string, time: int, attributes: array<string, scalar|null>}>
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * @param  list<array{name: string, time: int, attributes: array<string, scalar|null>}>  $events
     */
    public function replaceEvents(array $events): void
    {
        $this->events = $events;
    }

    public function context(): TraceContext
    {
        return new TraceContext($this->traceId, $this->spanId, $this->sampled, $this->traceState);
    }

    /**
     * The span in the OTLP/JSON encoding (opentelemetry.proto.trace.v1.Span).
     *
     * @return array<string, mixed>
     */
    public function toOtlp(): array
    {
        $span = [
            'traceId' => $this->traceId,
            'spanId' => $this->spanId,
            'name' => $this->name,
            'kind' => $this->kind->value,
            'startTimeUnixNano' => (string) $this->startNanos,
            'endTimeUnixNano' => (string) ($this->endNanos ?? $this->startNanos),
            'attributes' => self::encodeAttributes($this->attributes),
            'status' => ['code' => match ($this->status) {
                self::STATUS_OK => 1,
                self::STATUS_ERROR => 2,
                default => 0,
            }] + ($this->statusMessage !== null ? ['message' => $this->statusMessage] : []),
        ];

        if ($this->parentSpanId !== null) {
            $span['parentSpanId'] = $this->parentSpanId;
        }

        if ($this->traceState !== null) {
            $span['traceState'] = $this->traceState;
        }

        if ($this->events !== []) {
            $span['events'] = array_map(static fn (array $event): array => [
                'timeUnixNano' => (string) $event['time'],
                'name' => $event['name'],
                'attributes' => self::encodeAttributes($event['attributes']),
            ], $this->events);
        }

        return $span;
    }

    /**
     * @param  array<string, scalar|null>  $attributes
     * @return list<array{key: string, value: array<string, mixed>}>
     */
    public static function encodeAttributes(array $attributes): array
    {
        $encoded = [];

        foreach ($attributes as $key => $value) {
            if ($value === null) {
                continue;
            }

            $encoded[] = ['key' => $key, 'value' => match (true) {
                is_bool($value) => ['boolValue' => $value],
                is_int($value) => ['intValue' => (string) $value],
                is_float($value) => ['doubleValue' => $value],
                default => ['stringValue' => (string) $value],
            }];
        }

        return $encoded;
    }
}
