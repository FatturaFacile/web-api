<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Enums;

/**
 * Transport document (DDT) reasons recorded on RMAs and loaners. Flags only: no document is generated.
 */
enum DdtCausale: string
{
    case ContoRiparazione = 'conto_riparazione';
    case PrestitoUso = 'prestito_uso';
    case ResoRiparato = 'reso_riparato';
    case SostituzioneGaranzia = 'sostituzione_garanzia';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
