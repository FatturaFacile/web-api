<?php

declare(strict_types=1);

namespace Modules\Collaboration\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Shared behaviour of the collaboration payloads: the access decision lives in the module.access middleware and the
 * policy, and ids must belong to the active company.
 */
abstract class CollaborationFormRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware and the policy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A row of the table that belongs to the active company.
     */
    protected function ownRow(string $table): Exists
    {
        return Rule::exists($table, 'id')
            ->where('tenant_id', (string) app(TenantContext::class)->getTenantId())
            ->whereNull('deleted_at');
    }

    /**
     * A member of the active company.
     */
    protected function tenantUser(): Exists
    {
        return Rule::exists('tenant_user', 'user_id')->where('tenant_id', (string) app(TenantContext::class)->getTenantId());
    }
}
