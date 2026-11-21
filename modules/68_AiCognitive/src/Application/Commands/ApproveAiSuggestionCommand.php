<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Application\Commands;

/**
 * Approves a suggestion as proposed, or with the payload the reviewer corrected (Edit & Approve).
 */
final readonly class ApproveAiSuggestionCommand
{
    /**
     * @param  array<string, mixed>|null  $editedPayload
     */
    public function __construct(
        public string $tenantId,
        public ?string $userId,
        public string $suggestionId,
        public ?array $editedPayload = null,
        public ?string $feedback = null,
    ) {}
}
