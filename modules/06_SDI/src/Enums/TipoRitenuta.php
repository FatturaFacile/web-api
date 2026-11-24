<?php

declare(strict_types=1);

namespace Modules\SDI\Enums;

/**
 * TipoRitenutaType of the FatturaPA schema v1.2.3.
 */
enum TipoRitenuta: string
{
    case Rt01 = 'RT01';
    case Rt02 = 'RT02';
    case Rt03 = 'RT03';
    case Rt04 = 'RT04';
    case Rt05 = 'RT05';
    case Rt06 = 'RT06';

    /**
     * Withholding tax paid with the F24. The other types are contributions, each paid to its own fund.
     */
    public function isTax(): bool
    {
        return in_array($this, [self::Rt01, self::Rt02], true);
    }
}
