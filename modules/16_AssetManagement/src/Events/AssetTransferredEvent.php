<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class AssetTransferredEvent implements DomainEventInterface
{
    public function __construct(
        public string $transferId,
        public string $assetId,
        public ?string $fromLocationId,
        public ?string $toLocationId,
        public ?string $fromCustodianId,
        public ?string $toCustodianId,
        public ?string $fromProjectId,
        public ?string $toProjectId,
        public string $transferDate,
        public string $tenantId,
        public string $companyId,
        public string $eventId = '',
        public string $occurredAt = ''
    ) {}

    public function getEventId(): string
    {
        return $this->eventId !== '' ? $this->eventId : (string) Str::uuid7();
    }

    public function getAggregateType(): string
    {
        return 'eam_asset_transfer';
    }

    public function getAggregateId(): string
    {
        return $this->transferId;
    }

    public function getEventName(): string
    {
        return 'eam.asset.transferred';
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getOccurredAt(): string
    {
        return $this->occurredAt !== '' ? $this->occurredAt : now()->toIso8601String();
    }

    public function toPayload(): array
    {
        return get_object_vars($this);
    }
}
