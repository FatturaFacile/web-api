<?php

declare(strict_types=1);

namespace Enterprise\MultiTenant\Queue;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;

/**
 * Carries the active tenant across the queue. Without it a worker has no tenant and the global scope hides the data of
 * the company, so a queued job would silently find nothing.
 */
final class TenantQueueContext
{
    /**
     * Tenants active before each running job. Sync jobs run inside the request that dispatched them, which must get
     * its own tenant back afterwards.
     *
     * @var list<array{0: ?Tenant, 1: bool}>
     */
    private array $previous = [];

    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @return array{tenant_id: ?string}
     */
    public function payload(): array
    {
        return ['tenant_id' => $this->tenantContext->getTenantId()];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function enter(array $payload): void
    {
        $this->previous[] = [$this->tenantContext->isExplicit() ? $this->tenantContext->getTenant() : null, $this->tenantContext->isExplicit()];

        $tenantId = $payload['tenant_id'] ?? null;

        if ($tenantId !== null) {
            $this->tenantContext->setTenant(Tenant::find($tenantId));
        }
    }

    public function leave(): void
    {
        [$tenant, $wasExplicit] = array_pop($this->previous) ?? [null, false];

        $wasExplicit ? $this->tenantContext->setTenant($tenant) : $this->tenantContext->clear();
    }
}
