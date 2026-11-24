<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\DTOs;

final readonly class SubmitCSATDTO
{
    public function __construct(
        public string $tenantId,
        public string $companyId,
        public int $score,
        public ?string $feedback = null,
        public ?string $ticketId = null,
        public ?string $workOrderId = null,
        public ?string $customerId = null,
        public ?string $respondentEmail = null
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
