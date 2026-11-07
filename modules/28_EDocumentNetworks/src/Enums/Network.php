<?php

declare(strict_types=1);

namespace Modules\EDocumentNetworks\Enums;

enum Network: string
{
    case Ebill = 'ebill';
    case Peppol = 'peppol';
    case Nso = 'nso';

    public function label(): string
    {
        return match ($this) {
            self::Ebill => 'eBill (SIX)',
            self::Peppol => 'Peppol',
            self::Nso => 'NSO (Italian public administration ordering)',
        };
    }
}
