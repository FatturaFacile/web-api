<?php

declare(strict_types=1);

namespace Modules\SwissFiscal\Domain\ValueObjects;

use Modules\SwissFiscal\Domain\Exceptions\SwissFiscalException;

/** Structured postal address of a QR-bill party (address type "S"). */
final readonly class QrBillAddress
{
    public function __construct(
        public string $name,
        public string $postalCode,
        public string $town,
        public string $country,
        public string $street = '',
        public string $buildingNumber = '',
    ) {
        $this->assertLength('name', $name, 70, true);
        $this->assertLength('street', $street, 70);
        $this->assertLength('building number', $buildingNumber, 16);
        $this->assertLength('postal code', $postalCode, 16, true);
        $this->assertLength('town', $town, 35, true);

        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw SwissFiscalException::invalidQrBill('the country must be a two-letter ISO 3166 code');
        }
    }

    /**
     * The seven payload fields: type, name, street, building number, postal code, town, country.
     *
     * @return list<string>
     */
    public function payloadFields(): array
    {
        return ['S', $this->name, $this->street, $this->buildingNumber, $this->postalCode, $this->town, $this->country];
    }

    private function assertLength(string $field, string $value, int $max, bool $required = false): void
    {
        if ($required && trim($value) === '') {
            throw SwissFiscalException::invalidQrBill("the address {$field} is required");
        }

        if (mb_strlen($value) > $max) {
            throw SwissFiscalException::invalidQrBill("the address {$field} exceeds {$max} characters");
        }
    }
}
