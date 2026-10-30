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
            if ($tenantContext->isExplicit()) {
                // Enforce tenant_id strictly from active explicit TenantContext (e.g. HTTP request)
                $model->tenant_id = $tenantContext->getTenantId();
            } elseif (empty($model->tenant_id) && $tenantContext->hasTenant()) {
                $model->tenant_id = $tenantContext->getTenantId();
            }
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return $this
     */
    public function fill(array $attributes): static
    {
        if (isset($attributes['tenant_id']) && ! app(TenantContext::class)->isExplicit()) {
            $this->tenant_id = $attributes['tenant_id'];
            unset($attributes['tenant_id']);
        }

        return parent::fill($attributes);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id')->withDefault();
    }
}
