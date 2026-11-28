<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Domain\ValueObjects;

use App\Services\Crm\Fiscal\FiscalIdentifiers;
use Modules\OliveMillAndGrainMill\Domain\Exceptions\OliveMillException;

/**
 * CUAA of a SIAN counterparty: the Codice Fiscale (16 characters or 11 digits) or the Partita IVA of the farmer or the buyer, checked with the
 * checksum of the fiscal identifier.
 */
final readonly class SianCuaaIdentifier
{
    private function __construct(public string $value) {}

    /**
     * @throws OliveMillException invalid_cuaa
     */
    public static function fromString(string $raw): self
    {
        $code = FiscalIdentifiers::normalizeFiscalCode($raw);

        if (str_starts_with($code, 'IT') && preg_match('/^IT\d{11}$/', $code) === 1) {
            $code = substr($code, 2);
        }

        if (! FiscalIdentifiers::isValidItalianFiscalCode($code) && ! FiscalIdentifiers::isValidItalianVat($code)) {
            throw OliveMillException::because('invalid_cuaa', ['cuaa' => $raw]);
        }

        return new self($code);
    }

    public static function isValid(?string $raw): bool
    {
        if ($raw === null || trim($raw) === '') {
            return false;
        }

        try {
            self::fromString($raw);
        } catch (OliveMillException) {
            return false;
        }

        return true;
    }
}
