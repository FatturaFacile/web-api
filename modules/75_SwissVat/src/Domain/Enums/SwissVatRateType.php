<?php

declare(strict_types=1);

namespace Modules\SwissVat\Domain\Enums;

/**
 * Classification of Swiss VAT rate categories.
 */
enum SwissVatRateType: string
{
    case Standard = 'standard';
    case Reduced = 'reduced';
    case Accommodation = 'accommodation';
    case ZeroExempt = 'zero_exempt';
    case Saldosteuersatz = 'saldosteuersatz';
    case Bezugsteuer = 'bezugsteuer';
}
