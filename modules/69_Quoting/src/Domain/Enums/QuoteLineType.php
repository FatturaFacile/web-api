<?php

declare(strict_types=1);

namespace Modules\Quoting\Domain\Enums;

enum QuoteLineType: string
{
    case Product = 'product';
    case ServiceFixed = 'service_fixed';
    case ServiceTimeAndMaterial = 'service_time_and_material';
    case TextNote = 'text_note';
    case Subtotal = 'subtotal';

    public function isPriced(): bool
    {
        return ! in_array($this, [self::TextNote, self::Subtotal], true);
    }
}
