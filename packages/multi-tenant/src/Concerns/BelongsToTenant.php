<?php

declare(strict_types=1);

namespace Enterprise\MultiTenant\Concerns;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
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
     * Scope query to a specific tenant ID with fallback to global (null) records.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeForTenant($query, ?string $tenantId = null)
    {
        $column = $this->qualifyColumn('tenant_id');

        return $query->where(function ($q) use ($tenantId, $column): void {
            if (! empty($tenantId)) {
                $q->where($column, $tenantId)
                    ->orWhereNull($column);
            } else {
                $q->whereNull($column);
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
