<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * The exists rule of a tenant table: the row must belong to the active company or be generic, and not be deleted.
 */
final class TenantScopedRules
{
    /**
     * A row of a soft-deleting table of the active company (or generic).
     */
    public static function exists(string $table): Exists
    {
        return self::existsInTable($table)->whereNull('deleted_at');
    }

    /**
     * A row of a table without soft deletes (the payroll tables) of the active company (or generic).
     */
    public static function existsInTable(string $table): Exists
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return Rule::exists($table, 'id')
            ->where(static fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'));
    }
}
