<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Infrastructure\Sian;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\OliveMillAndGrainMill\Application\Contracts\SianReceipt;
use Modules\OliveMillAndGrainMill\Application\Contracts\SianTransport;

/**
 * Posts the batch as XML to the SIAN web service configured in `olive_mill.sian` and reads the protocol number of the receipt from the answer
 * (`{"protocol": "..."}`).
 */
final class HttpSianTransport implements SianTransport
{
    public function transmit(string $xml, int $operations): SianReceipt
    {
        $endpoint = (string) config('olive_mill.sian.endpoint', '');

        if ($endpoint === '') {
            return new SianReceipt(false, null, 'sian_endpoint_missing');
        }

        try {
            $response = Http::withToken((string) config('olive_mill.sian.token', ''))
                ->timeout((int) config('olive_mill.sian.timeout', 20))
                ->withBody($xml, 'application/xml')
                ->acceptJson()
                ->post($endpoint);
        } catch (ConnectionException) {
            return new SianReceipt(false, null, 'sian_unreachable');
        }

        if ($response->failed()) {
            return new SianReceipt(false, null, 'sian_refused_'.$response->status());
        }

        $protocol = $response->json('protocol');

        return is_string($protocol) && $protocol !== ''
            ? new SianReceipt(true, $protocol)
            : new SianReceipt(false, null, 'sian_protocol_missing');
    }
}
