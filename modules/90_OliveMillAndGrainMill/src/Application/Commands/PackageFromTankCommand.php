<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Application\Commands;

use Modules\OliveMillAndGrainMill\Domain\Enums\BottleFormat;

/**
 * Bottling of oil from a tank into retail formats with the packaging lot (SIAN causal S03).
 */
final readonly class PackageFromTankCommand
{
    public function __construct(
        public string $tankId,
        public BottleFormat $bottleFormat,
        public int $bottleCount,
        public ?string $operationDate,
        public ?string $packageLot,
        public ?string $notes,
        public ?string $userId,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated request data
     */
    public static function fromArray(array $data, ?string $userId = null): self
    {
        return new self(
            tankId: (string) $data['tank_id'],
            bottleFormat: BottleFormat::from((string) $data['bottle_format']),
            bottleCount: (int) $data['bottle_count'],
            operationDate: isset($data['operation_date']) ? (string) $data['operation_date'] : null,
            packageLot: isset($data['package_lot']) ? (string) $data['package_lot'] : null,
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
            userId: $userId,
        );
    }
}
