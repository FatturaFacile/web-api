<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Application\Commands;

/**
 * Rectification of a tank stock for natural loss or a count difference (SIAN causal R01).
 */
final readonly class AdjustTankStockCommand
{
    public function __construct(
        public string $tankId,
        public float $quantityKg,
        public ?string $operationDate,
        public string $reason,
        public ?string $userId,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated request data
     */
    public static function fromArray(array $data, ?string $userId = null): self
    {
        return new self(
            tankId: (string) $data['tank_id'],
            quantityKg: (float) $data['quantity_kg'],
            operationDate: isset($data['operation_date']) ? (string) $data['operation_date'] : null,
            reason: (string) $data['reason'],
            userId: $userId,
        );
    }
}
