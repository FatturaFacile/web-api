<?php

declare(strict_types=1);

namespace Modules\CRM\DTOs;

final readonly class CreateActivityDTO
{
    public function __construct(
        public string $tenantId,
        public ?string $companyId,
        public string $subject,
        public string $type, // task, call, meeting, note, email, follow_up
        public string $priority = 'medium',
        public ?string $dueDate = null,
        public ?string $assignedUserId = null,
        public ?string $relatedType = null, // lead, account, contact, opportunity, campaign
        public ?string $relatedId = null,
        public ?int $durationMinutes = null,
        public ?string $location = null,
        public ?string $notes = null,
        public array $metadata = []
    ) {}
}
