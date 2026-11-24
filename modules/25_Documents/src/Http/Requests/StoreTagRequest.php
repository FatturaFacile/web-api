<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the input of the store tag operation (authorization is decided by module.access).
 */
final class StoreTagRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'name' => ['required', 'string', 'max:80', Rule::unique('dms_tags', 'name')->whereNull('deleted_at')->where(static fn (Builder $query): Builder => $query->where('tenant_id', $tenantId))],
            'color' => ['nullable', 'string', 'max:20'],
        ];
    }
}
