<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Validation rules that keep every id of a workflow request inside the active company: a row of another company, or a
 * generic one, does not exist for the request.
 */
final class TenantRule
{
    /**
     * A row of a table of the active company (soft-deleting tables, deleted rows do not exist).
     */
    public static function exists(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at');
    }

    /**
     * A user who is a member of the active company.
     */
    public static function member(): Exists
    {
        return Rule::exists('tenant_user', 'user_id')->where('tenant_id', app(TenantContext::class)->getTenantId());
    }
}
