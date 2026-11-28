<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Application\Commands;

use Modules\OliveMillAndGrainMill\Domain\Enums\ByproductType;

/**
 * Dispatch or use of a by-product: pomace to the pomace mill, pits as fuel, vegetation water spread on land, bran.
 */
final readonly class RecordByproductMovementCommand
{
    public function __construct(
        public ByproductType $byproductType,
        public string $movementDate,
        public float $quantity,
        public ?string $deliveryId,
        public ?string $cycleId,
        public ?string $counterpartyContactId,
        public ?string $counterpartyName,
        public ?string $destinationType,
        public ?string $transportDocument,
        public ?string $cadastralParcel,
        public ?float $parcelAreaHa,
        public ?string $notes,
        public ?string $userId,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated request data
     */
    public static function fromArray(array $data, ?string $userId = null): self
    {
        return new self(
            byproductType: ByproductType::from((string) $data['byproduct_type']),
            movementDate: (string) $data['movement_date'],
            quantity: (float) $data['quantity'],
            deliveryId: isset($data['delivery_id']) ? (string) $data['delivery_id'] : null,
            cycleId: isset($data['cycle_id']) ? (string) $data['cycle_id'] : null,
            counterpartyContactId: isset($data['counterparty_contact_id']) ? (string) $data['counterparty_contact_id'] : null,
            counterpartyName: isset($data['counterparty_name']) ? (string) $data['counterparty_name'] : null,
            destinationType: isset($data['destination_type']) ? (string) $data['destination_type'] : null,
            transportDocument: isset($data['transport_document']) ? (string) $data['transport_document'] : null,
            cadastralParcel: isset($data['cadastral_parcel']) ? (string) $data['cadastral_parcel'] : null,
            parcelAreaHa: isset($data['parcel_area_ha']) ? (float) $data['parcel_area_ha'] : null,
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
            userId: $userId,
        );
    }
}
