<?php

declare(strict_types=1);

namespace Enterprise\MultiTenant\Scopes;

use Enterprise\MultiTenant\Services\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Isolates rows per legal entity. Rows with a null company_id are tenant-wide generic data and stay visible.
 * A no-op while no company context is set, so tenant-wide jobs and legacy single-company tenants are unaffected.
 */
final class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(CompanyContext::class);
        $column = $model->qualifyColumn('company_id');

        if ($context->hasActive()) {
            $active = (string) $context->activeId();
            $builder->where(function (Builder $query) use ($column, $active): void {
                $query->where($column, $active)->orWhereNull($column);
            });

            return;
        }

        $allowed = $context->allowedIds();
        if ($allowed !== null) {
            $builder->where(function (Builder $query) use ($column, $allowed): void {
                $query->whereIn($column, $allowed)->orWhereNull($column);
            });
        }
    }
}
