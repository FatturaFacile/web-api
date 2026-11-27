<?php

declare(strict_types=1);

use Modules\Observability\Tracing\TraceContext;

it('parses a valid W3C traceparent with its tracestate', function (): void {
    $context = TraceContext::fromHeaders('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', 'vendor=value');

    expect($context)->not->toBeNull()
        ->and($context->traceId)->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($context->spanId)->toBe('00f067aa0ba902b7')
        ->and($context->sampled)->toBeTrue()
        ->and($context->traceState)->toBe('vendor=value')
        ->and($context->toTraceparent())->toBe('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');
});

it('reads the sampled flag', function (): void {
    $context = TraceContext::fromHeaders('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00');

    expect($context->sampled)->toBeFalse()
        ->and($context->toTraceparent())->toEndWith('-00');
});

it('rejects malformed or forbidden traceparent values', function (?string $header): void {
    expect(TraceContext::fromHeaders($header))->toBeNull();
})->with([
    'missing' => [null],
    'empty' => [''],
    'garbage' => ['not-a-traceparent'],
    'short trace id' => ['00-4bf92f3577b34da6a3ce929d0e0e47-00f067aa0ba902b7-01'],
    'zero trace id' => ['00-00000000000000000000000000000000-00f067aa0ba902b7-01'],
    'zero span id' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01'],
    'forbidden version' => ['ff-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'],
    'version 00 with extra data' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01-extra'],
    'upper case hex is normalised' => ['00-4BF92F3577B34DA6A3CE929D0E0E4736-00F067AA0BA902B7-0G'],
]);

it('accepts a future version that appends fields', function (): void {
    $context = TraceContext::fromHeaders('01-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01-extra');

    expect($context?->traceId)->toBe('4bf92f3577b34da6a3ce929d0e0e4736');
});

it('drops an oversized tracestate', function (): void {
    $context = TraceContext::fromHeaders('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', str_repeat('a', 600));

    expect($context->traceState)->toBeNull();
});

it('generates non-zero ids of the right length', function (): void {
    expect(TraceContext::newTraceId())->toMatch('/^[0-9a-f]{32}$/')
        ->and(TraceContext::newSpanId())->toMatch('/^[0-9a-f]{16}$/')
        ->and(TraceContext::newTraceId())->not->toBe(TraceContext::newTraceId());
});
