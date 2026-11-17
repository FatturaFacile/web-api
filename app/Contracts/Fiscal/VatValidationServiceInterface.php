<?php

declare(strict_types=1);

namespace App\Contracts\Fiscal;

use App\DTOs\Fiscal\VatValidationResult;
use App\Exceptions\Fiscal\InvalidVatFormatException;

interface VatValidationServiceInterface
{
    /**
     * Checks the formal syntax of an EU VAT number and then its active intra-Community status.
     *
     * @param  string  $vatNumber  With or without country prefix ("DE123456789" or "123456789").
     * @param  string|null  $countryCode  ISO country code of the counterparty; GR is accepted for Greece (EL).
     *
     * @throws InvalidVatFormatException when the syntax is wrong (fail-fast, before any network call)
     */
    public function validate(string $vatNumber, ?string $countryCode = null): VatValidationResult;
}
