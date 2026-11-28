<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Application\Commands;

/**
 * Puts a weighed delivery in the hopper: the milling cycle starts.
 */
final readonly class StartMillingCycleCommand
{
    public function __construct(
        public string $deliveryId,
        public ?string $lineCode,
        public ?string $startedAt,
        public ?float $malaxationTemperatureC,
        public ?float $waterAddedL,
        public ?string $userId,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated request data
     */
    public static function fromArray(array $data, ?string $userId = null): self
    {
        return new self(
            deliveryId: (string) $data['delivery_id'],
            lineCode: isset($data['line_code']) ? (string) $data['line_code'] : null,
            startedAt: isset($data['started_at']) ? (string) $data['started_at'] : null,
            malaxationTemperatureC: isset($data['malaxation_temperature_c']) ? (float) $data['malaxation_temperature_c'] : null,
            waterAddedL: isset($data['water_added_l']) ? (float) $data['water_added_l'] : null,
            userId: $userId,
        );
    }
}
