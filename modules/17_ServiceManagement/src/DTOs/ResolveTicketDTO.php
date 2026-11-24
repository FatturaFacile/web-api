<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\DTOs;

final readonly class ResolveTicketDTO
{
    public function __construct(
        public string $ticketId,
        public ?string $resolutionNotes = null,
        public ?string $rootCause = null,
        public ?string $resolvedBy = null,
        public ?string $knowledgeArticleId = null
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
