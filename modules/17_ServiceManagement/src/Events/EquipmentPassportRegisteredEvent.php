<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * Emitted when a serialized equipment passport is registered.
 */
final readonly class EquipmentPassportRegisteredEvent implements DomainEventInterface
{
    public function __construct(
        public string $passportId,
        public string $serialNumber,
        public ?string $productId,
        public string $customerId,
        public string $warrantyPolicyType,
        public string $warrantyExpiryDate,
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
        return 'esm_equipment_passport';
    }

    public function getAggregateId(): string
    {
        return $this->passportId;
    }

    public function getEventName(): string
    {
        return 'esm.equipment_passport.registered';
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
