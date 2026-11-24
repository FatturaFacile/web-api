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

    private int $globalWriteDepth = 0;

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

    /**
     * Whether the code running now declared that the records it writes are global (tenant_id null, visible to every
     * tenant): see runAsGlobal().
     */
    public function isWritingGlobal(): bool
    {
        return $this->globalWriteDepth > 0;
    }

    /**
     * Runs the callback declaring that every record it creates is global data shared by all tenants (lookups,
     * translations, catalogues, platform settings, legal reference data). Only models that allow global records
     * (AllowsGlobalRecords) may be written inside it, and from an explicit tenant context only a platform
     * administrator may. This is the only way to write a row with a null tenant_id.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runAsGlobal(callable $callback): mixed
    {
        $this->globalWriteDepth++;

        try {
            return $callback();
        } finally {
            $this->globalWriteDepth--;
        }
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
     * Temporarily executes the callback with no tenant at all (console and system code such as seeders of shared data),
     * restoring the previous context afterwards.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runWithoutTenant(callable $callback): mixed
    {
        $previousTenant = $this->tenant;
        $previousExplicit = $this->isExplicit;
        $previousExplicitlyNull = $this->explicitlyNull;

        $this->clear();

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
