<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Tenant;
use Closure;

class TenantContext
{
    private ?Tenant $tenant = null;

    private bool $isExplicit = false;

    public function setTenant(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->isExplicit = $tenant !== null;
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

        if (auth()->check()) {
            $user = auth()->user();
            if ($user instanceof \App\Models\User) {
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
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runWithTenant(?Tenant $temporaryTenant, Closure $callback): mixed
    {
        $previousTenant = $this->tenant;
        $this->tenant = $temporaryTenant;

        try {
            return $callback();
        } finally {
            $this->tenant = $previousTenant;
        }
    }
}
