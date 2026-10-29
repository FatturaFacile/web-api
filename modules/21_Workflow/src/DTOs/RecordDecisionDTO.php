<?php

declare(strict_types=1);

namespace Modules\Workflow\DTOs;

final readonly class RecordDecisionDTO
{
    /**
     * @param  array<string, mixed>|null  $signaturePayload
     */
    public function __construct(
        public string $workflowInstanceStepId,
        public string $decision, // approved, rejected, abstained
        public ?string $userId = null,
        public ?string $comment = null,
        public ?string $idempotencyKey = null,
        public ?string $approvalTokenId = null,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public ?array $signaturePayload = null
    ) {}
}
