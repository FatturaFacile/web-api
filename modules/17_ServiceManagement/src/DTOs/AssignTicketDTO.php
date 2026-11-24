<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\DTOs;

final readonly class AssignTicketDTO
{
    public function __construct(
        public string $ticketId,
        public string $assignedToId,
        public ?string $serviceTeamId = null,
        public ?string $assignedBy = null
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
