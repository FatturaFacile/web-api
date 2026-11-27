<?php

declare(strict_types=1);

namespace Modules\Observability\Connectors;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Modules\Observability\Privacy\PiiScrubber;
use Throwable;

/**
 * Synthetic probe: reachability, HTTP status, TLS validity (verified handshake, days to expiry) and latency of one institutional service.
 * No credentials and no tenant data are ever sent; the call is not traced (observability_skip).
 */
final class ConnectorProbe
{
    public function __construct(
        private readonly ConnectorCatalog $catalog,
        private readonly TlsCertificateInspector $tls,
        private readonly PiiScrubber $scrubber,
    ) {}

    public function probe(string $key): ProbeResult
    {
        $connector = $this->catalog->find($key) ?? throw new InvalidArgumentException("Unknown connector [{$key}].");
        $timeout = (float) config('observability.connectors.timeout_seconds', 8);
        $started = hrtime(true);

        try {
            $response = Http::withOptions(['observability_skip' => true])
                ->timeout((int) ceil($timeout))
                ->connectTimeout(min(5, (int) ceil($timeout)))
                ->withHeaders(['User-Agent' => 'FatturaFacile-StatusProbe/1.0', 'Accept' => '*/*'])
                ->withoutRedirecting()
                ->get($connector['url']);
        } catch (ConnectionException $exception) {
            return new ProbeResult(false, null, $this->elapsed($started), null, $this->looksLikeTlsFailure($exception->getMessage()) ? false : null, $this->describe($exception));
        } catch (Throwable $exception) {
            return new ProbeResult(false, null, $this->elapsed($started), null, null, $this->describe($exception));
        }

        $latency = $this->elapsed($started);
        $status = $response->status();
        $ok = in_array($status, $connector['expected'], true);
        $host = (string) parse_url($connector['url'], PHP_URL_HOST);
        $days = null;

        if (str_starts_with($connector['url'], 'https://') && $host !== '') {
            try {
                $days = $this->tls->daysRemaining($host, 443, min(5.0, $timeout));
            } catch (Throwable) {
                $days = null;
            }
        }

        return new ProbeResult($ok, $status, $latency, $days, $days !== null ? $days >= 0 : true, $ok ? null : 'HTTP '.$status);
    }

    private function elapsed(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }

    private function describe(Throwable $exception): string
    {
        return substr($this->scrubber->scrubString(preg_replace('/\s+/', ' ', $exception->getMessage()) ?? ''), 0, 240);
    }

    private function looksLikeTlsFailure(string $message): bool
    {
        return preg_match('/ssl|tls|certificate/i', $message) === 1;
    }
}
