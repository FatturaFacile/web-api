<?php

declare(strict_types=1);

namespace Modules\Observability\Tracing;

/**
 * W3C Trace Context (https://www.w3.org/TR/trace-context/): the `traceparent` and `tracestate` headers that Angular, WPF, Flutter and the
 * other services send and that this API forwards to its outgoing calls and queued jobs.
 */
final readonly class TraceContext
{
    public function __construct(
        public string $traceId,
        public string $spanId,
        public bool $sampled = true,
        public ?string $traceState = null,
    ) {}

    public static function fromHeaders(?string $traceparent, ?string $tracestate = null): ?self
    {
        if ($traceparent === null || $traceparent === '') {
            return null;
        }

        if (preg_match('/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})(?:-.*)?$/', strtolower(trim($traceparent)), $match) !== 1) {
            return null;
        }

        [, $version, $traceId, $spanId, $flags] = $match;

        if ($version === 'ff' || ($version === '00' && strlen(trim($traceparent)) !== 55)) {
            return null;
        }

        if (trim($traceId, '0') === '' || trim($spanId, '0') === '') {
            return null;
        }

        $state = $tracestate !== null ? trim($tracestate) : null;

        return new self($traceId, $spanId, (hexdec($flags) & 1) === 1, $state !== null && $state !== '' && strlen($state) <= 512 ? $state : null);
    }

    public function toTraceparent(): string
    {
        return sprintf('00-%s-%s-%s', $this->traceId, $this->spanId, $this->sampled ? '01' : '00');
    }

    public static function newTraceId(): string
    {
        do {
            $id = bin2hex(random_bytes(16));
        } while (trim($id, '0') === '');

        return $id;
    }

    public static function newSpanId(): string
    {
        do {
            $id = bin2hex(random_bytes(8));
        } while (trim($id, '0') === '');

        return $id;
    }
}
