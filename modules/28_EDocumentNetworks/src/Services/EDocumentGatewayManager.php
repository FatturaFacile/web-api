<?php

declare(strict_types=1);

namespace Modules\EDocumentNetworks\Services;

use Modules\EDocumentNetworks\Contracts\EDocumentGateway;
use Modules\EDocumentNetworks\Enums\Network;
use Modules\EDocumentNetworks\Gateways\LocalGateway;
use Modules\EDocumentNetworks\Gateways\RefusingGateway;

/**
 * Edocument gateway manager (EDocumentNetworks module).
 */
final class EDocumentGatewayManager
{
    /**
     * For.
     */
    public function for(Network $network): EDocumentGateway
    {
        return match (config("edocument_networks.drivers.{$network->value}")) {
            'local' => new LocalGateway($network),
            default => new RefusingGateway($network),
        };
    }
}
