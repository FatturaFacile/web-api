<?php

declare(strict_types=1);

namespace Modules\Workflow\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final readonly class FiscalDocumentLockedEvent implements DomainEventInterface
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $documentType,
        public string $documentId,
        public string $instanceId,
        public string $tenantId,
        public string $companyId
    ) {}

    public function getEventName(): string
    {
        return 'workflow.fiscal.locked';
    }

    public function getAggregateType(): string
    {
        return 'FiscalDocument';
    }

    public function getAggregateId(): string
    {
        return $this->documentId;
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
            'document_type' => $this->documentType,
            'document_id' => $this->documentId,
            'instance_id' => $this->instanceId,
        ];
    }
}
