<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Application\Contracts;

/**
 * Sends a SIAN telemetry batch of the oil register to the ministry web service. The transport hides whether the file goes to the real service or
 * to the local sandbox that acknowledges everything.
 */
interface SianTransport
{
    public function transmit(string $xml, int $operations): SianReceipt;
}
