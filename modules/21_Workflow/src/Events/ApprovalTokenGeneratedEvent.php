<?php

declare(strict_types=1);

namespace Modules\Workflow\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final readonly class ApprovalTokenGeneratedEvent implements DomainEventInterface
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $tokenId,
        public string $stepId,
        public string $actionType,
        public string $actionUrl,
        public string $authorizedUserId,
        public string $tenantId,
        public string $companyId
    ) {}

    public function getEventName(): string
    {
        return 'workflow.token.generated';
    }

    public function getAggregateType(): string
    {
        return 'ApprovalToken';
    }

    public function getAggregateId(): string
    {
        return $this->tokenId;
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function toPayload(): array
    {
        return [
            'token_id' => $this->tokenId,
            'step_id' => $this->stepId,
            'action_type' => $this->actionType,
            'action_url' => $this->actionUrl,
            'authorized_user_id' => $this->authorizedUserId,
        ];
    }
}
