<?php

declare(strict_types=1);

namespace Modules\Manufacturing\DTOs;

final readonly class CreateEcoDTO
{
    /**
     * @param  array<string, mixed>|null  $changePayload
     */
    public function __construct(
        public string $tenantId,
        public string $title,
        public string $reason,
        public ?string $description = null,
        public string $changeType = 'bom_change',
        public ?string $effectiveDate = null,
        public ?array $changePayload = null,
        public ?string $requestedBy = null
    ) {}
}
