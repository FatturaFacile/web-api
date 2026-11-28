<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Application\Commands;

/**
 * Transfer or blend of oil from one tank to another (SIAN causal T01).
 */
final readonly class TransferBetweenTanksCommand
{
    public function __construct(
        public string $fromTankId,
        public string $toTankId,
        public float $quantityKg,
        public ?string $operationDate,
        public ?string $notes,
        public ?string $userId,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated request data
     */
    public static function fromArray(array $data, ?string $userId = null): self
    {
        return new self(
            fromTankId: (string) $data['from_tank_id'],
            toTankId: (string) $data['to_tank_id'],
            quantityKg: (float) $data['quantity_kg'],
            operationDate: isset($data['operation_date']) ? (string) $data['operation_date'] : null,
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
            userId: $userId,
        );
    }
}
