<?php

declare(strict_types=1);

namespace App\Services\Banking\Iso20022;

/**
 * Category purpose of a pain.001 payment information block (ISO 20022 ExternalCategoryPurpose1Code). SALA marks
 * salary payments, which Swiss banks book as a collective salary credit.
 */
enum Pain001CategoryPurpose: string
{
    case Salary = 'SALA';
    case Supplier = 'SUPP';
    case Tax = 'TAXS';
    case Pension = 'PENS';
    case Bonus = 'BONU';
    case Trade = 'TRAD';
}
