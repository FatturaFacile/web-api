<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\DTOs;

final readonly class EscalateTicketDTO
{
    public function __construct(
        public string $ticketId,
        public string $reason,
        public int $escalationLevel = 1,
        public ?string $escalatedBy = null
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
