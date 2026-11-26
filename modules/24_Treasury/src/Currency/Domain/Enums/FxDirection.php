<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Enums;

/**
 * Side of a settled document: a receivable gains when the foreign currency is worth more at payment than at invoicing, a
 * payable loses.
 */
enum FxDirection: string
{
    case Receivable = 'receivable';
    case Payable = 'payable';
}
