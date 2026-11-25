<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Contracts;

/**
 * Transmission of the electronic service sheet (FDSE) to the register of the Ministry of Infrastructure and Transport (MIT).
 */
interface FdseGatewayInterface
{
    /**
     * @param  array<string, mixed>  $payload
     * @return string the protocol code assigned to the transmission
     */
    public function transmit(array $payload): string;
}
