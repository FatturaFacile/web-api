<?php

declare(strict_types=1);

namespace Modules\Observability\Tracing;

use Illuminate\Support\Facades\Http;

/**
 * OTLP/HTTP exporter with the JSON encoding (POST {endpoint}/v1/traces). The calls carry the `observability_skip` option, so the exporter
 * is never traced by the outgoing HTTP instrumentation.
 */
final class OtlpHttpJsonSpanExporter implements SpanExporter
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, scalar>  $resourceAttributes
     */
    public function __construct(
        private readonly string $endpoint,
        private readonly array $headers,
        private readonly array $resourceAttributes,
        private readonly float $timeoutSeconds = 2.0,
        private readonly int $batchSize = 200,
    ) {}

    /**
     * Parses the OTEL_EXPORTER_OTLP_HEADERS notation "key=value,key2=value2".
     *
     * @return array<string, string>
     */
    public static function parseHeaders(?string $raw): array
    {
        $headers = [];

        foreach (explode(',', (string) $raw) as $pair) {
            if (! str_contains($pair, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $pair, 2);
            $key = trim($key);

            if ($key !== '') {
                $headers[$key] = trim($value);
            }
        }

        return $headers;
    }

    public static function tracesUrl(string $endpoint): string
    {
        $endpoint = rtrim($endpoint, '/');

        return str_ends_with($endpoint, '/v1/traces') ? $endpoint : $endpoint.'/v1/traces';
    }

    public function export(array $spans): void
    {
        foreach (array_chunk($spans, max(1, $this->batchSize)) as $chunk) {
            Http::withOptions(['observability_skip' => true])
                ->timeout((int) ceil($this->timeoutSeconds))
                ->connectTimeout(1)
                ->withHeaders($this->headers)
                ->asJson()
                ->post(self::tracesUrl($this->endpoint), $this->payload($chunk));
        }
    }

    /**
     * @param  list<Span>  $spans
     * @return array<string, mixed>
     */
    public function payload(array $spans): array
    {
        return ['resourceSpans' => [[
            'resource' => ['attributes' => Span::encodeAttributes($this->resourceAttributes)],
            'scopeSpans' => [[
                'scope' => ['name' => 'fatturafacile.observability', 'version' => '1.0.0'],
                'spans' => array_map(static fn (Span $span): array => $span->toOtlp(), $spans),
            ]],
        ]]];
    }
}
