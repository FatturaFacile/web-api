<?php

declare(strict_types=1);

namespace Modules\SwissVat\Domain\Enums;

/**
 * Swiss VAT reporting method under LIVA / MWSTG / LTVA.
 */
enum SwissVatMethod: string
{
    case Effective = 'effective';
    case Saldosteuersatz = 'saldosteuersatz';
}
