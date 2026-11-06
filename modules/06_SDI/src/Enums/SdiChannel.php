<?php

declare(strict_types=1);

namespace Modules\SDI\Enums;

/**
 * The ways a tenant can talk to the Italian Exchange System (SDI).
 */
enum SdiChannel: string
{
    case Pec = 'pec';
    case Openapi = 'openapi';
    case Acube = 'acube';
    case Invoicetronic = 'invoicetronic';
    case Local = 'local';

    /**
     * Whether files really leave the application, so they must pass every fiscal check first.
     */
    public function isReal(): bool
    {
        return $this !== self::Local;
    }

    /**
     * Whether the channel relies on the credentials of an external API provider.
     */
    public function isApiProvider(): bool
    {
        return in_array($this, [self::Openapi, self::Acube, self::Invoicetronic], true);
    }
}
