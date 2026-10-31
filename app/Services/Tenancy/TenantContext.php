<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Models\User;

class TenantContext
{
    private ?Tenant $tenant = null;

    private bool $isExplicit = false;

    private bool $explicitlyNull = false;

    public function setTenant(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->isExplicit = $tenant !== null;
        $this->explicitlyNull = $tenant === null;
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->isExplicit = false;
        $this->explicitlyNull = false;
    }

    public function isExplicit(): bool
    {
        return $this->isExplicit;
    }

    public function getTenant(): ?Tenant
    {
        if ($this->tenant !== null) {
            return $this->tenant;
        }

        if ($this->explicitlyNull) {
            return null;
        }

        if (auth()->check()) {
            $user = auth()->user();
            if ($user instanceof User) {
                return $user->tenants()->first();
            }
        }

        return null;
    }

    public function getTenantId(): ?string
    {
        return $this->getTenant()?->id;
    }

    public function hasTenant(): bool
    {
        return $this->getTenant() !== null;
    }

    /**
     * Temporarily execute callback in the context of another tenant.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runWithTenant(Tenant $tenant, callable $callback): mixed
    {
        $previousTenant = $this->tenant;
        $previousExplicit = $this->isExplicit;
        $previousExplicitlyNull = $this->explicitlyNull;

        $this->setTenant($tenant);

        try {
            return $callback();
        } finally {
            $this->tenant = $previousTenant;
            $this->isExplicit = $previousExplicit;
            $this->explicitlyNull = $previousExplicitlyNull;
        }
    }

    /**
     * Temporarily execute callback in the context of another tenant.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runFor(Tenant $tenant, callable $callback): mixed
    {
        return $this->runWithTenant($tenant, $callback);
    }
}
