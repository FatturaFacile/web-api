<?php

declare(strict_types=1);

namespace Modules\EDocumentNetworks\Exceptions;

use Modules\EDocumentNetworks\Enums\Network;
use RuntimeException;

/**
 * Raised when network not available.
 */
final class NetworkNotAvailableException extends RuntimeException
{
    /**
     * Contract required.
     */
    public static function contractRequired(Network $network): self
    {
        return new self("{$network->label()} needs a contract with a provider: no real connection is available.");
    }

    /**
     * Simulator in production.
     */
    public static function simulatorInProduction(Network $network): self
    {
        return new self("The local {$network->label()} simulator does not run in production.");
    }
}
