<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Application\Commands;

/**
 * Records the weighbridge reading of a delivery (manual entry or a scale integration payload).
 */
final readonly class WeighOliveDeliveryCommand
{
    public function __construct(
        public string $deliveryId,
        public float $grossWeightKg,
        public float $tareKg,
        public int $crateCount,
        public float $crateUnitTareKg,
        public ?string $weighbridgeTicket,
        public ?array $scalePayload,
        public ?string $scaleReadAt,
        public ?string $userId,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated request data
     */
    public static function fromArray(array $data, ?string $userId = null): self
    {
        return new self(
            deliveryId: (string) $data['delivery_id'],
            grossWeightKg: (float) $data['gross_weight_kg'],
            tareKg: (float) $data['tare_kg'],
            crateCount: (int) $data['crate_count'],
            crateUnitTareKg: (float) $data['crate_unit_tare_kg'],
            weighbridgeTicket: isset($data['weighbridge_ticket']) ? (string) $data['weighbridge_ticket'] : null,
            scalePayload: isset($data['scale_payload']) ? (array) $data['scale_payload'] : null,
            scaleReadAt: isset($data['scale_read_at']) ? (string) $data['scale_read_at'] : null,
            userId: $userId,
        );
    }
}
