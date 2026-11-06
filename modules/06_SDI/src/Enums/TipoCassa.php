<?php

declare(strict_types=1);

namespace Modules\SDI\Enums;

/**
 * TipoCassaType of the FatturaPA schema v1.2.3.
 */
enum TipoCassa: string
{
    case Tc01 = 'TC01';
    case Tc02 = 'TC02';
    case Tc03 = 'TC03';
    case Tc04 = 'TC04';
    case Tc05 = 'TC05';
    case Tc06 = 'TC06';
    case Tc07 = 'TC07';
    case Tc08 = 'TC08';
    case Tc09 = 'TC09';
    case Tc10 = 'TC10';
    case Tc11 = 'TC11';
    case Tc12 = 'TC12';
    case Tc13 = 'TC13';
    case Tc14 = 'TC14';
    case Tc15 = 'TC15';
    case Tc16 = 'TC16';
    case Tc17 = 'TC17';
    case Tc18 = 'TC18';
    case Tc19 = 'TC19';
    case Tc20 = 'TC20';
    case Tc21 = 'TC21';
    case Tc22 = 'TC22';

    /**
     * TC22 is INPS: its rivalsa is income of the professional, while what the other funds collect through the invoice is
     * owed to the fund.
     */
    public function isRivalsaInps(): bool
    {
        return $this === self::Tc22;
    }
}
