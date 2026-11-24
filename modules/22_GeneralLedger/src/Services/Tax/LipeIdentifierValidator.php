<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Tax;

use App\Services\Crm\Fiscal\FiscalIdentifiers;

/**
 * Semantic checks of the LIPE (IVP18) data that the structural schema cannot express: check digit of the Partita IVA, the
 * tax codes (11 digit company code with a valid check digit, or the 16 character personal code with its check
 * character), the declarant role code (1 to 15, "codice carica" table of the model), the tax year and the periodicity.
 */
final class LipeIdentifierValidator
{
    /** First tax year of the LIPE communication (D.L. 193/2016, applicable from 2017). */
    public const int FIRST_YEAR = 2017;

    /**
     * @return list<string> the errors, empty when the data can be written to a valid file
     */
    public static function errors(LipeData $data): array
    {
        $errors = [];

        if (! FiscalIdentifiers::isValidItalianVat($data->vatNumber) || strlen($data->vatNumber) !== 11) {
            $errors[] = 'The Partita IVA is not valid (11 digits with a correct check digit).';
        }

        if (! self::isValidTaxCode($data->taxCode)) {
            $errors[] = 'The company tax code is not valid.';
        }

        if ($data->declarantTaxCode !== null && $data->declarantTaxCode !== '') {
            if (! self::isValidTaxCode($data->declarantTaxCode)) {
                $errors[] = 'The declarant tax code is not valid.';
            }

            if (! in_array($data->declarantRoleCode, array_map('strval', range(1, 15)), true)) {
                $errors[] = 'The declarant role code must be between 1 and 15.';
            }
        }

        if ($data->year < self::FIRST_YEAR || $data->year > 2100) {
            $errors[] = 'The tax year is out of range.';
        }

        if (($data->month === null) === ($data->quarter === null)) {
            $errors[] = 'Exactly one of month and quarter must be set.';
        }

        if ($data->month !== null && ($data->month < 1 || $data->month > 12)) {
            $errors[] = 'The month must be between 1 and 12.';
        }

        if ($data->quarter !== null && ($data->quarter < 1 || $data->quarter > 4)) {
            $errors[] = 'The quarter must be between 1 and 4.';
        }

        return $errors;
    }

    /**
     * An 11 digit tax code is a company code (same check digit as the Partita IVA); a 16 character one is a personal code.
     */
    public static function isValidTaxCode(string $taxCode): bool
    {
        return match (strlen($taxCode)) {
            11 => FiscalIdentifiers::isValidItalianVat($taxCode),
            16 => FiscalIdentifiers::isValidItalianFiscalCode($taxCode),
            default => false,
        };
    }
}
