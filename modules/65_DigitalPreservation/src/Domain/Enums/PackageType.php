<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Domain\Enums;

/**
 * OAIS package types: submission (PdV), archival (PdA) and distribution (PdD).
 */
enum PackageType: string
{
    case Pdv = 'PdV';
    case Pda = 'PdA';
    case Pdd = 'PdD';
}
