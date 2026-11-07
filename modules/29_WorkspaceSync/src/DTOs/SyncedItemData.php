<?php

declare(strict_types=1);

namespace Modules\WorkspaceSync\DTOs;

final readonly class SyncedItemData
{
    public function __construct(
        public string $kind,
        public string $externalId,
        public ?string $subject,
        public ?string $counterpart,
        public ?string $startsAt,
        public ?string $endsAt = null,
    ) {}
}
