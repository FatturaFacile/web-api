<?php

declare(strict_types=1);

namespace Modules\EDocumentNetworks\Enums;

/**
 * Allowed values of network in the EDocumentNetworks module.
 */
enum Network: string
{
    case Ebill = 'ebill';
    case Peppol = 'peppol';
    case Nso = 'nso';

    /**
     * Human label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Ebill => 'eBill (SIX)',
            self::Peppol => 'Peppol',
            self::Nso => 'NSO (Italian public administration ordering)',
        };
    }
}
