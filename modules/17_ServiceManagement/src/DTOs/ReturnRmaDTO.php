<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\DTOs;

final readonly class ReturnRmaDTO
{
    public function __construct(
        public ?string $returnedAt = null,
        public ?string $ddtCausaleReturn = null,
        public ?bool $vatExemptArt15Dpr633 = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromValidated(array $data): self
    {
        return new self(
            returnedAt: $data['returned_at'] ?? null,
            ddtCausaleReturn: $data['ddt_causale_return'] ?? null,
            vatExemptArt15Dpr633: array_key_exists('vat_exempt_art15_dpr633', $data) ? (bool) $data['vat_exempt_art15_dpr633'] : null,
        );
    }
}
