<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Infrastructure\Sian;

use Illuminate\Support\Str;
use Modules\OliveMillAndGrainMill\Application\Contracts\SianReceipt;
use Modules\OliveMillAndGrainMill\Application\Contracts\SianTransport;

/**
 * Local sandbox of the SIAN: validates that the batch is well formed XML and acknowledges it with a generated protocol number. Nothing leaves
 * the server.
 */
final class SandboxSianTransport implements SianTransport
{
    public function transmit(string $xml, int $operations): SianReceipt
    {
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($document === false || $operations < 1) {
            return new SianReceipt(false, null, 'malformed_batch');
        }

        return new SianReceipt(true, 'SBX-'.now()->format('Ymd').'-'.strtoupper(Str::random(8)));
    }
}
