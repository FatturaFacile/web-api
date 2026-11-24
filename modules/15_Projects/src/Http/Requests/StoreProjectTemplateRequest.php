<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Saves a reusable project template, either from the WBS of an existing project or from an explicit structure.
 */
final class StoreProjectTemplateRequest extends FormRequest
{
    use TenantScopedRules;

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
            'code' => ['required', 'string', 'max:50', Rule::unique('project_templates', 'code')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'source_project_id' => ['nullable', 'uuid', 'required_without:structure', $this->tenantOwns('projects')],
            'structure' => ['nullable', 'array', 'required_without:source_project_id'],
            'structure.nodes' => ['required_with:structure', 'array', 'min:1'],
            'structure.nodes.*.title' => ['required', 'string', 'max:255'],
            'structure.nodes.*.node_type' => ['nullable', 'string'],
            'structure.dependencies' => ['nullable', 'array'],
            'structure.dependencies.*.predecessor' => ['required', 'string'],
            'structure.dependencies.*.successor' => ['required', 'string'],
            'structure.dependencies.*.type' => ['nullable', 'string', Rule::in(['FS', 'SS', 'FF', 'SF'])],
            'structure.dependencies.*.lag_days' => ['nullable', 'integer', 'between:-3650,3650'],
        ];
    }
}
