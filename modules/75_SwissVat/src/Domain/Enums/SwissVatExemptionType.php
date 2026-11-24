<?php

declare(strict_types=1);

namespace Modules\SwissVat\Domain\Enums;

/**
 * Distinguishes zero-rated/exempt supplies with input tax deduction (Art. 23 LIVA)
 * from exempt supplies without input tax deduction (Art. 21 LIVA) and out-of-scope flows (Art. 18(2) LIVA).
 */
enum SwissVatExemptionType: string
{
    case None = 'none';
    case ExemptWithDeduction = 'exempt_with_deduction';
    case ExemptWithoutDeduction = 'exempt_without_deduction';
    case OutOfScope = 'out_of_scope';
}
