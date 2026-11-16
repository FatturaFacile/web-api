<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\DTOs;

final readonly class ReceiveRmaDTO
{
    /**
     * @param  list<array{url?: string|null, asset_id?: string|null, caption?: string|null}>  $receiptPhotos
     */
    public function __construct(
        public ?string $receivedAt = null,
        public ?string $receiptConditionNotes = null,
        public array $receiptPhotos = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromValidated(array $data): self
    {
        return new self(
            receivedAt: $data['received_at'] ?? null,
            receiptConditionNotes: $data['receipt_condition_notes'] ?? null,
            receiptPhotos: array_values($data['receipt_photos'] ?? []),
        );
    }
}
