<?php

declare(strict_types=1);

namespace Modules\Copilot\DTOs;

/**
 * The ERP screen the user is looking at, sent by the client with each prompt.
 */
final readonly class PromptContext
{
    public function __construct(
        public ?string $route = null,
        public ?string $entityType = null,
        public ?string $entityId = null,
    ) {}
}
