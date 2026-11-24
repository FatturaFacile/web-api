<?php

declare(strict_types=1);

namespace Enterprise\MultiTenant\Scopes;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Visibility rule of the tenant tables: a tenant sees its own rows and, only for the models that allow global records
 * (AllowsGlobalRecords), the rows with a null tenant_id. Without a tenant context only those global rows are visible.
 * For every other model a null tenant_id is invisible to everybody.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantContext = app(TenantContext::class);
        $column = $model->qualifyColumn('tenant_id');
        $includesGlobal = method_exists($model, 'allowsGlobalRecords') && $model->allowsGlobalRecords();
        $tenantId = $tenantContext->hasTenant() ? $tenantContext->getTenantId() : null;

        $builder->where(function (Builder $query) use ($column, $tenantId, $includesGlobal): void {
            if (! empty($tenantId)) {
                $query->where($column, $tenantId);

                if ($includesGlobal) {
                    $query->orWhereNull($column);
                }
            } elseif ($includesGlobal) {
                $query->whereNull($column);
            } else {
                $query->whereRaw('1 = 0');
            }
        });
    }
}
