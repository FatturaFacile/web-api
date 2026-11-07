<?php

declare(strict_types=1);

namespace Modules\EDocumentNetworks\Gateways;

use App\Models\Tenant;
use Modules\EDocumentNetworks\Contracts\EDocumentGateway;
use Modules\EDocumentNetworks\DTOs\EDocumentEnvelope;
use Modules\EDocumentNetworks\Enums\Network;
use Modules\EDocumentNetworks\Exceptions\NetworkNotAvailableException;

/**
 * The default until a provider contract exists: it refuses, so nothing can look delivered when it was not.
 */
final readonly class RefusingGateway implements EDocumentGateway
{
    public function __construct(private Network $network) {}

    public function name(): string
    {
        return 'refused';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function send(Tenant $tenant, EDocumentEnvelope $envelope): array
    {
        throw NetworkNotAvailableException::contractRequired($this->network);
    }
}
