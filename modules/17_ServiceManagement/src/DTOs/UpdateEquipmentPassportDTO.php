<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\DTOs;

/**
 * Partial update: only keys present in $attributes are applied (null clears nullable fields).
 */
final readonly class UpdateEquipmentPassportDTO
{
    public const UPDATABLE = [
        'current_owner_customer_id',
        'original_sales_invoice_id',
        'customer_type',
        'jurisdiction',
        'delivery_date',
        'contract_warranty_months',
        'contract_clause_reference',
        'installed_firmware_version',
        'notes',
    ];

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(public array $attributes) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromValidated(array $data): self
    {
        $attributes = array_intersect_key($data, array_flip(self::UPDATABLE));

        if (isset($attributes['jurisdiction'])) {
            $attributes['jurisdiction'] = strtoupper((string) $attributes['jurisdiction']);
        }

        return new self($attributes);
    }
}
