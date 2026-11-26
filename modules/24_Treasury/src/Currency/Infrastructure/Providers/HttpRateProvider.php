<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Infrastructure\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\Treasury\Currency\Domain\Contracts\RateProvider;
use Modules\Treasury\Currency\Domain\Exceptions\RateProviderException;

/**
 * Common HTTP behaviour of the official feeds: a timeout, a retry with growing back-off, and a refusal (exception) for any
 * answer that is not a successful, non-empty body. Parsing and schema validation belong to the subclass.
 */
abstract class HttpRateProvider implements RateProvider
{
    /**
     * @param  array<string, string|int>  $query
     * @param  list<int>  $backoffMs
     *
     * @throws RateProviderException
     */
    protected function download(string $url, int $timeout, array $backoffMs, array $query = []): string
    {
        try {
            $response = Http::timeout($timeout)->withHeaders(['Accept' => '*/*'])->retry($backoffMs, throw: false)->get($url, $query);
        } catch (ConnectionException $exception) {
            throw new RateProviderException(sprintf('%s feed unreachable: %s', strtoupper($this->source()->value), $exception->getMessage()), 0, $exception);
        }

        if (! $response->successful()) {
            throw new RateProviderException(sprintf('%s feed answered HTTP %d.', strtoupper($this->source()->value), $response->status()));
        }

        $body = trim($response->body());

        if ($body === '') {
            throw new RateProviderException(sprintf('%s feed is empty.', strtoupper($this->source()->value)));
        }

        return $body;
    }
}
