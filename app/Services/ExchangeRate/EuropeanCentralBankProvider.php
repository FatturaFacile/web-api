<?php

declare(strict_types=1);

namespace App\Services\ExchangeRate;

use App\Contracts\ExchangeRateProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class EuropeanCentralBankProvider implements ExchangeRateProviderInterface
{
    private const ECB_FEED_URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

    /**
     * @return array<int, array{source: string, target: string, rate: float}>
     */
    public function getRates(string $baseCurrency = 'EUR'): array
    {
        try {
            $response = Http::timeout(3)->get(self::ECB_FEED_URL);
            if ($response->successful()) {
                $xml = simplexml_load_string($response->body());
                if ($xml !== false) {
                    $rates = [];
                    $cube = $xml->xpath('//*[@currency and @rate]');
                    $parsed = [];
                    foreach ($cube as $node) {
                        $parsed[(string) $node['currency']] = (float) $node['rate'];
                    }

                    if (isset($parsed['CHF'])) {
                        $rates[] = ['source' => 'EUR', 'target' => 'CHF', 'rate' => $parsed['CHF']];
                        $rates[] = ['source' => 'CHF', 'target' => 'EUR', 'rate' => round(1 / $parsed['CHF'], 6)];
                    }
                    if (isset($parsed['USD'])) {
                        $rates[] = ['source' => 'USD', 'target' => 'EUR', 'rate' => round(1 / $parsed['USD'], 6)];
                        if (isset($parsed['CHF'])) {
                            $rates[] = ['source' => 'USD', 'target' => 'CHF', 'rate' => round($parsed['CHF'] / $parsed['USD'], 6)];
                        }
                    }

                    if (! empty($rates)) {
                        return $rates;
                    }
                }
            }
        } catch (Throwable $e) {
            Log::warning('ECB exchange feed offline or unreachable, falling back to reference rates: '.$e->getMessage());
        }

        return $this->fallbackReferenceRates();
    }

    /**
     * @return array<int, array{source: string, target: string, rate: float}>
     */
    private function fallbackReferenceRates(): array
    {
        return [
            ['source' => 'EUR', 'target' => 'CHF', 'rate' => 0.942500],
            ['source' => 'CHF', 'target' => 'EUR', 'rate' => 1.061008],
            ['source' => 'USD', 'target' => 'EUR', 'rate' => 0.921500],
            ['source' => 'USD', 'target' => 'CHF', 'rate' => 0.868500],
        ];
    }
}
