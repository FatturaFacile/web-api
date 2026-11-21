<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Application\Commands;

final readonly class RejectAiSuggestionCommand
{
    public function __construct(
        public string $tenantId,
        public ?string $userId,
        public string $suggestionId,
        public string $reason,
    ) {}
}
