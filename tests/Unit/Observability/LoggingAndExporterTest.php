<?php

declare(strict_types=1);

use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Http;
use Modules\Observability\Logging\EnrichLogTap;
use Modules\Observability\Logging\JsonLogTap;
use Modules\Observability\Logging\ObservabilityLogProcessor;
use Modules\Observability\Privacy\PiiScrubber;
use Modules\Observability\Tracing\InMemorySpanExporter;
use Modules\Observability\Tracing\OtlpHttpJsonSpanExporter;
use Modules\Observability\Tracing\Tracer;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;

it('adds the trace, span and service to a record and scrubs message and context', function (): void {
    $span = app(Tracer::class)->startSpan('request');
    $record = new LogRecord(new DateTimeImmutable, 'app', Level::Info, 'Sent to mario@example.com', ['iban' => 'CH9300762011623852957', 'order' => 12], []);

    $processed = (new ObservabilityLogProcessor)($record);

    expect($processed->message)->toBe('Sent to [EMAIL]')
        ->and($processed->context['iban'])->toBe(PiiScrubber::MASK)
        ->and($processed->context['order'])->toBe(12)
        ->and($processed->extra['trace_id'])->toBe($span->traceId)
        ->and($processed->extra['span_id'])->toBe($span->spanId)
        ->and($processed->extra['service'])->toBe(config('observability.service.name'));
});

it('keeps exception objects in the context of a plain channel', function (): void {
    $exception = new RuntimeException('x');
    $monolog = new MonologLogger('t', [$handler = new TestHandler]);
    (new EnrichLogTap)(new Logger($monolog, app('events')));

    $monolog->error('failed', ['exception' => $exception, 'email' => 'a@b.ch']);

    expect($handler->getRecords()[0]->context['exception'])->toBe($exception)
        ->and($handler->getRecords()[0]->context['email'])->toBe(PiiScrubber::MASK);
});

it('writes one scrubbed JSON object per line with the correlation ids', function (): void {
    $span = app(Tracer::class)->startSpan('request');
    $stream = fopen('php://memory', 'r+');
    $monolog = new MonologLogger('t', [new StreamHandler($stream)]);
    (new JsonLogTap)(new Logger($monolog, app('events')));

    $monolog->warning('Pay CH9300762011623852957 now', ['email' => 'a@b.ch', 'error' => new RuntimeException('bad mario@example.com'), 'ok' => 1]);

    rewind($stream);
    $line = json_decode(trim((string) stream_get_contents($stream)), true, 512, JSON_THROW_ON_ERROR);

    expect($line['message'])->toBe('Pay [IBAN] now')
        ->and($line['context']['email'])->toBe(PiiScrubber::MASK)
        ->and($line['context']['error']['message'])->toBe('bad [EMAIL]')
        ->and($line['context']['ok'])->toBe(1)
        ->and($line['extra']['trace_id'])->toBe($span->traceId)
        ->and($line['level_name'])->toBe('WARNING');
});

it('posts the spans to the OTLP traces endpoint with the configured headers', function (): void {
    Http::fake(['collector.test/*' => Http::response([], 200)]);
    $tracer = new Tracer(new InMemorySpanExporter, new PiiScrubber);
    $span = $tracer->startSpan('GET /x');
    $tracer->endSpan($span);

    (new OtlpHttpJsonSpanExporter('https://collector.test:4318', ['Authorization' => 'Bearer t0ken'], ['service.name' => 'fatturafacile-api']))->export([$span]);

    Http::assertSent(function ($request) use ($span): bool {
        $resource = $request['resourceSpans'][0];

        return $request->url() === 'https://collector.test:4318/v1/traces'
            && $request->header('Authorization') === ['Bearer t0ken']
            && $resource['resource']['attributes'][0] === ['key' => 'service.name', 'value' => ['stringValue' => 'fatturafacile-api']]
            && $resource['scopeSpans'][0]['spans'][0]['traceId'] === $span->traceId
            && $resource['scopeSpans'][0]['spans'][0]['name'] === 'GET /x';
    });
});

it('parses OTLP headers and builds the traces url', function (): void {
    expect(OtlpHttpJsonSpanExporter::parseHeaders('api-key=abc, x-tenant = main ,broken'))->toBe(['api-key' => 'abc', 'x-tenant' => 'main'])
        ->and(OtlpHttpJsonSpanExporter::parseHeaders(null))->toBe([])
        ->and(OtlpHttpJsonSpanExporter::tracesUrl('http://otel:4318/'))->toBe('http://otel:4318/v1/traces')
        ->and(OtlpHttpJsonSpanExporter::tracesUrl('http://otel:4318/v1/traces'))->toBe('http://otel:4318/v1/traces');
});
