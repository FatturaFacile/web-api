<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Analytics\Semantic\SemanticCatalog;

/**
 * Validates a designed report. The specification is checked against the dataset by the report engine, which knows the
 * user (access is decided by module.access, the policy of the report and the module of the dataset).
 */
final class SaveDesignedReportRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware, the policy and the report engine.
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
            'name' => [
                'required', 'string', 'max:150',
                Rule::unique('analytics_designed_reports', 'name')
                    ->where('tenant_id', $tenantId)
                    ->where('user_id', $this->user()?->id)
                    ->whereNull('deleted_at')
                    ->ignore($this->route('id')),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'dataset' => ['required', 'string', Rule::in(array_keys(app(SemanticCatalog::class)->all()))],
            'spec' => ['required', 'array'],
            'is_shared' => ['nullable', 'boolean'],
            'shared_with' => ['nullable', 'array', 'max:50'],
            'shared_with.*' => ['uuid', 'distinct', Rule::exists('tenant_user', 'user_id')->where('tenant_id', $tenantId)],
        ];
    }
}
