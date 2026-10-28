<?php

declare(strict_types=1);

namespace Enterprise\MultiTenant\Concerns;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            $tenantContext = app(TenantContext::class);
            if ($tenantContext->hasTenant()) {
                // Enforce tenant_id strictly from active TenantContext to prevent parameter injection
                $model->tenant_id = $tenantContext->getTenantId();
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id')->withDefault();
    }
}
