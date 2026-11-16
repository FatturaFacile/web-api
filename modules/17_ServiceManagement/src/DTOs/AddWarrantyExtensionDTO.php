<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\DTOs;

final readonly class AddWarrantyExtensionDTO
{
    public function __construct(
        public string $name,
        public int $months,
        public string $startsAt,
        public ?string $reference = null,
        public string $price = '0.00',
        public ?string $currency = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromValidated(array $data): self
    {
        return new self(
            name: (string) $data['name'],
            months: (int) $data['months'],
            startsAt: (string) $data['starts_at'],
            reference: $data['reference'] ?? null,
            price: number_format((float) ($data['price'] ?? 0), 2, '.', ''),
            currency: $data['currency'] ?? null,
        );
    }
}
