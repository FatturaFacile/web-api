<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\DTOs;

final readonly class CompleteWorkOrderDTO
{
    public function __construct(
        public string $workOrderId,
        public ?string $completionNotes = null,
        public ?string $resolutionSummary = null,
        public ?string $customerSignatureUrl = null,
        public ?string $signedByCustomerName = null,
        public ?string $signedAt = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(...$data);
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
