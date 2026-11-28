<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Application\Commands;

use Modules\BondedWarehouses\Domain\Enums\VatDepotMovementType;

/**
 * Load row of the Art. 50-bis register: goods introduced in free circulation or from another EU State, or an internal transfer between IVA warehouses.
 */
final readonly class RegisterVatDepot50BisInboundCommand
{
    public function __construct(
        public string $authorizationId,
        public VatDepotMovementType $movementType,
        public string $movementDate,
        public string $productId,
        public ?string $lotReference,
        public float $quantity,
        public float $baseGoodsValue,
        public ?string $notes,
        public ?string $userId,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated request data
     */
    public static function fromArray(array $data, ?string $userId): self
    {
        return new self(
            authorizationId: (string) $data['authorization_id'],
            movementType: VatDepotMovementType::from((string) $data['movement_type']),
            movementDate: (string) $data['movement_date'],
            productId: (string) $data['product_id'],
            lotReference: isset($data['lot_reference']) ? (string) $data['lot_reference'] : null,
            quantity: (float) $data['quantity'],
            baseGoodsValue: (float) ($data['base_goods_value'] ?? 0),
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
            userId: $userId,
        );
    }
}
