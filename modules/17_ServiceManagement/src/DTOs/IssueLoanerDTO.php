<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\DTOs;

final readonly class IssueLoanerDTO
{
    public function __construct(
        public string $registerType,
        public ?string $loanerSerialNumber = null,
        public ?string $loanerStockSerialNumberId = null,
        public ?string $loanerProductId = null,
        public ?string $issuedAt = null,
        public ?string $expectedReturnAt = null,
        public ?string $ddtCausale = null,
        public ?string $notes = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromValidated(array $data): self
    {
        return new self(
            registerType: (string) $data['register_type'],
            loanerSerialNumber: $data['loaner_serial_number'] ?? null,
            loanerStockSerialNumberId: $data['loaner_stock_serial_number_id'] ?? null,
            loanerProductId: $data['loaner_product_id'] ?? null,
            issuedAt: $data['issued_at'] ?? null,
            expectedReturnAt: $data['expected_return_at'] ?? null,
            ddtCausale: $data['ddt_causale'] ?? null,
            notes: $data['notes'] ?? null,
        );
    }
}
