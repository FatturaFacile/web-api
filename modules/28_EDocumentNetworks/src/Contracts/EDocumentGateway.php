<?php

declare(strict_types=1);

namespace Modules\EDocumentNetworks\Contracts;

use App\Models\Tenant;
use Modules\EDocumentNetworks\DTOs\EDocumentEnvelope;
use Modules\EDocumentNetworks\Exceptions\NetworkNotAvailableException;

/**
 * What a provider of a document network must offer. A real driver is written once a provider contract exists.
 */
interface EDocumentGateway
{
    /**
     * Driver or resolver name.
     */
    public function name(): string;

    /**
     * Whether this driver can move documents at all in the current environment.
     */
    public function isAvailable(): bool;

    /**
     * @return array{external_id: string, status: string}
     *
     * @throws NetworkNotAvailableException
     */
    public function send(Tenant $tenant, EDocumentEnvelope $envelope): array;
}
