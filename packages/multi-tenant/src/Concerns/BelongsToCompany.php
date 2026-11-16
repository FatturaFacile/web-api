<?php

declare(strict_types=1);

namespace Enterprise\MultiTenant\Concerns;

use Enterprise\MultiTenant\Scopes\CompanyScope;
use Enterprise\MultiTenant\Services\CompanyContext;

trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function ($model): void {
            $context = app(CompanyContext::class);

            if (empty($model->company_id) && $context->hasActive()) {
                $model->company_id = $context->activeId();
            }
        });
    }
}
