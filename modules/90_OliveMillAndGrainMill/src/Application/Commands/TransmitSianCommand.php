<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Application\Commands;

/**
 * Validates and transmits the pending operations of the oil register to the SIAN (the named ones, or every pending one up to the date).
 */
final readonly class TransmitSianCommand
{
    public function __construct(
        public ?array $movementIds,
        public ?string $dateTo,
        public ?string $userId,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated request data
     */
    public static function fromArray(array $data, ?string $userId = null): self
    {
        return new self(
            movementIds: isset($data['movement_ids']) ? (array) $data['movement_ids'] : null,
            dateTo: isset($data['date_to']) ? (string) $data['date_to'] : null,
            userId: $userId,
        );
    }
}
