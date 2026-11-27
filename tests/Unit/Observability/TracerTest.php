<?php

declare(strict_types=1);

use Modules\Observability\Privacy\PiiScrubber;
use Modules\Observability\Tracing\InMemorySpanExporter;
use Modules\Observability\Tracing\Span;
use Modules\Observability\Tracing\SpanExporter;
use Modules\Observability\Tracing\SpanKind;
use Modules\Observability\Tracing\TraceContext;
use Modules\Observability\Tracing\Tracer;

function obsTracer(?InMemorySpanExporter &$exporter = null, float $ratio = 1.0): Tracer
{
    $exporter = new InMemorySpanExporter;

    return new Tracer($exporter, new PiiScrubber, $ratio);
}

it('continues the trace of a remote parent and nests the child spans', function (): void {
    $tracer = obsTracer($exporter);
    $remote = TraceContext::fromHeaders('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', 'vendor=1');

    $root = $tracer->startSpan('GET /x', SpanKind::Server, [], $remote);
    $child = $tracer->startSpan('db', SpanKind::Client);
    $tracer->endSpan($child);
    $tracer->endSpan($root);
    $tracer->flush();

    expect($root->traceId)->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($root->parentSpanId)->toBe('00f067aa0ba902b7')
        ->and($root->traceState)->toBe('vendor=1')
        ->and($child->parentSpanId)->toBe($root->spanId)
        ->and($child->traceId)->toBe($root->traceId)
        ->and($exporter->spans)->toHaveCount(2)
        ->and($tracer->isIdle())->toBeTrue()
        ->and($tracer->currentContext())->toBeNull();
});

it('starts a new trace when there is no parent', function (): void {
    $tracer = obsTracer();
    $span = $tracer->startSpan('job');

    expect($span->parentSpanId)->toBeNull()
        ->and($span->traceId)->toMatch('/^[0-9a-f]{32}$/')
        ->and($tracer->currentContext()->spanId)->toBe($span->spanId);
});

it('exports nothing when the root is not sampled and follows an unsampled parent', function (): void {
    $tracer = obsTracer($exporter, 0.0);
    $span = $tracer->startSpan('root');
    $tracer->endSpan($span);
    $tracer->flush();

    expect($span->sampled)->toBeFalse()->and($exporter->spans)->toBe([])
        ->and($span->context()->toTraceparent())->toEndWith('-00');

    $tracer = obsTracer($exporter, 1.0);
    $remote = TraceContext::fromHeaders('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00');
    $tracer->endSpan($tracer->startSpan('child', SpanKind::Server, [], $remote));
    $tracer->flush();

    expect($exporter->spans)->toBe([]);
});

it('scrubs names, attributes, events and statuses when a span ends', function (): void {
    $tracer = obsTracer($exporter);
    $span = $tracer->startSpan('send to mario@example.com', SpanKind::Client, ['customer.email' => 'mario@example.com', 'note' => 'IBAN CH9300762011623852957', 'http.status' => 200]);
    $span->addEvent('retry', ['reason' => 'user a@b.ch']);
    $span->setStatus(Span::STATUS_ERROR, 'failed for 756.1234.5678.97');
    $tracer->endSpan($span);
    $tracer->flush();

    $exported = $exporter->spans[0];

    expect($exported->name)->toBe('send to [EMAIL]')
        ->and($exported->attributes()['customer.email'])->toBe(PiiScrubber::MASK)
        ->and($exported->attributes()['note'])->toBe('IBAN [IBAN]')
        ->and($exported->attributes()['http.status'])->toBe(200)
        ->and($exported->events()[0]['attributes']['reason'])->toBe('user [EMAIL]')
        ->and($exported->statusMessage)->toBe('failed for [AVS]');
});

it('records an exception as an error status with an exception event', function (): void {
    $tracer = obsTracer($exporter);
    $span = $tracer->startSpan('job');
    $tracer->endSpan($span, new RuntimeException('boom'));

    expect($span->status)->toBe(Span::STATUS_ERROR)
        ->and($span->events()[0]['name'])->toBe('exception')
        ->and($span->events()[0]['attributes']['exception.type'])->toBe(RuntimeException::class);
});

it('closes the children that were left open and ignores a second end', function (): void {
    $tracer = obsTracer($exporter);
    $root = $tracer->startSpan('root');
    $orphan = $tracer->startSpan('orphan');

    $tracer->endSpan($root);
    $tracer->endSpan($root);
    $tracer->flush();

    expect($orphan->isEnded())->toBeTrue()->and($tracer->isIdle())->toBeTrue()->and($exporter->spans)->toHaveCount(2);
});

it('encodes a span in OTLP JSON', function (): void {
    $tracer = obsTracer();
    $span = $tracer->startSpan('GET /x', SpanKind::Server, ['http.route' => '/x', 'http.response.status_code' => 200, 'ratio' => 0.5, 'ok' => true, 'skipped' => null]);
    $span->setStatus(Span::STATUS_OK);
    $tracer->endSpan($span);

    $otlp = $span->toOtlp();

    expect($otlp['kind'])->toBe(2)
        ->and($otlp['status'])->toBe(['code' => 1])
        ->and($otlp)->not->toHaveKey('parentSpanId')
        ->and($otlp['attributes'])->toContain(['key' => 'http.route', 'value' => ['stringValue' => '/x']])
        ->and($otlp['attributes'])->toContain(['key' => 'http.response.status_code', 'value' => ['intValue' => '200']])
        ->and($otlp['attributes'])->toContain(['key' => 'ratio', 'value' => ['doubleValue' => 0.5]])
        ->and($otlp['attributes'])->toContain(['key' => 'ok', 'value' => ['boolValue' => true]])
        ->and(array_column($otlp['attributes'], 'key'))->not->toContain('skipped')
        ->and((int) $otlp['endTimeUnixNano'])->toBeGreaterThanOrEqual((int) $otlp['startTimeUnixNano']);
});

it('swallows an exporter failure', function (): void {
    $exporter = new class implements SpanExporter
    {
        public function export(array $spans): void
        {
            throw new RuntimeException('collector down');
        }
    };
    $tracer = new Tracer($exporter, new PiiScrubber);
    $tracer->endSpan($tracer->startSpan('x'));

    $tracer->flush();

    expect(true)->toBeTrue();
});
