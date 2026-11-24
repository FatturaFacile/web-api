<?php

declare(strict_types=1);

namespace Modules\Associations\Support;

use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Validation rules bound to the active company: a request can never point at a record of another tenant.
 */
final class TenantRules
{
    /**
     * The record exists for this company, or is a generic record shared with every company.
     */
    public static function exists(string $table, bool $softDeletes = true): Exists
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $rule = Rule::exists($table, 'id')->where(static fn ($query) => $query->where(static fn ($inner) => $inner->where('tenant_id', $tenantId)->orWhereNull('tenant_id')));

        return $softDeletes ? $rule->whereNull('deleted_at') : $rule;
    }

    /**
     * Unique among the live records of this company.
     */
    public static function unique(string $table, string $column, ?string $ignoreId = null): Unique
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $rule = Rule::unique($table, $column)->where(static fn ($query) => $query->where('tenant_id', $tenantId))->whereNull('deleted_at');

        return $ignoreId === null ? $rule : $rule->ignore($ignoreId);
    }
}
