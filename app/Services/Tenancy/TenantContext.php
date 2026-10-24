<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Tenant;
use Closure;

class TenantContext
{
    private ?Tenant $tenant = null;

    public function setTenant(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getTenantId(): ?string
    {
        return $this->tenant?->id;
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null;
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
