<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Tenant;
use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Contracts\EventBusInterface;
use Illuminate\Support\Str;

/**
 * Shared set-up of the Academy tests: sandbox tenants and ERP domain events raised the way the modules raise them.
 */
final class AcademyScenario
{
    public static function tenant(string $name, bool $sandbox): Tenant
    {
        return Tenant::create(['name' => $name, 'legal_name' => $name.' SA', 'settings' => $sandbox ? ['sandbox' => true] : []]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function raise(Tenant $tenant, string $eventName, array $payload = []): void
    {
        app(EventBusInterface::class)->dispatch(new class($tenant->id, $eventName, $payload) implements DomainEventInterface
        {
            /**
             * @param  array<string, mixed>  $payload
             */
            public function __construct(private readonly string $tenantId, private readonly string $name, private readonly array $payload) {}

            public function getEventName(): string
            {
                return $this->name;
            }

            public function getAggregateType(): string
            {
                return 'test';
            }

            public function getAggregateId(): string
            {
                return (string) Str::uuid();
            }

            public function getTenantId(): string
            {
                return $this->tenantId;
            }

            public function getCompanyId(): string
            {
                return $this->tenantId;
            }

            public function toPayload(): array
            {
                return $this->payload;
            }
        });
    }
}
