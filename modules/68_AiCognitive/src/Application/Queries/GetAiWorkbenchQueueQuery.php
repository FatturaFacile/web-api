<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Application\Queries;

/**
 * The review queue: open items by default, newest and most urgent first.
 */
final readonly class GetAiWorkbenchQueueQuery
{
    public function __construct(
        public string $tenantId,
        public ?string $status = null,
        public ?string $kind = null,
        public ?string $priority = null,
        public int $perPage = 25,
        public ?string $suggestionId = null,
    ) {}
}
