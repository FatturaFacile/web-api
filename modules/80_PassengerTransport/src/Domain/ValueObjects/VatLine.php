<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Domain\ValueObjects;

use Modules\PassengerTransport\Domain\Enums\VatLineKind;

/**
 * One invoice line of the VAT split: the share of the fare of a territory with its rate, its natura code (FatturaPA) and the legal reference.
 */
final readonly class VatLine
{
    public function __construct(
        public VatLineKind $kind,
        public string $km,
        public string $net,
        public string $rate,
        public string $tax,
        public ?string $nature,
        public string $legalReference,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'km' => $this->km,
            'net' => $this->net,
            'rate' => $this->rate,
            'tax' => $this->tax,
            'nature' => $this->nature,
            'legal_reference' => $this->legalReference,
        ];
    }
}
