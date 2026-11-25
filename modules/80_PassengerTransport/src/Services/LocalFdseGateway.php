<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Services;

use Modules\PassengerTransport\Contracts\FdseGatewayInterface;

/**
 * Default gateway: assigns a deterministic protocol code from the payload and keeps the sheet in the archive of the company. The
 * national register of the MIT needs the credentials of the operator; bind another FdseGatewayInterface to transmit to it.
 */
final class LocalFdseGateway implements FdseGatewayInterface
{
    public function transmit(array $payload): string
    {
        return 'FDSE-'.strtoupper(substr(hash('sha256', (string) json_encode($payload)), 0, 16));
    }
}
