<?php

declare(strict_types=1);

namespace Enterprise\MultiTenant\Concerns;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

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

        static::updating(static fn (Model $model) => static::assertNotGenericRecord($model));
        static::deleting(static fn (Model $model) => static::assertNotGenericRecord($model));
    }

    /**
     * Generic records (tenant_id null) are shared by every tenant of the installation, so a request acting for one
     * tenant can read them but not change them. Console and system code run without an explicit tenant and may.
     *
     * @throws AccessDeniedHttpException
     */
    protected static function assertNotGenericRecord(Model $model): void
    {
        $original = $model->getOriginal();

        if (array_key_exists('tenant_id', $original) && $original['tenant_id'] === null && app(TenantContext::class)->isExplicit()) {
            throw new AccessDeniedHttpException('Generic records are read-only for tenant users.');
        }
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
