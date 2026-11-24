<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Domain\Enums;

/**
 * Where a sealed submission package is sent.
 */
enum PreservationProvider: string
{
    case LocalWorm = 'local_worm';
    case ArubaPec = 'aruba_pec';
    case InfoCert = 'infocert';
    case Namirial = 'namirial';
    case Zucchetti = 'zucchetti';

    /**
     * Code written in the PdV.xml `ConservatoreDestinazione` element.
     */
    public function pdvCode(): string
    {
        return strtoupper($this->value);
    }
}
