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

    private const int MAX_DEPTH = 8;

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
            ...$this->nodeRules('structure.nodes.*'),
            'structure.dependencies' => ['nullable', 'array'],
            'structure.dependencies.*.predecessor' => ['required', 'string'],
            'structure.dependencies.*.successor' => ['required', 'string'],
            'structure.dependencies.*.type' => ['nullable', 'string', Rule::in(['FS', 'SS', 'FF', 'SF'])],
            'structure.dependencies.*.lag_days' => ['nullable', 'integer', 'between:-3650,3650'],
        ];
    }

    /**
     * Rules of a template node and, recursively, of its children (down to MAX_DEPTH levels), so that every field of the
     * tree (`ref` included, which the dependencies point to) survives validation.
     *
     * @return array<string, list<mixed>>
     */
    private function nodeRules(string $prefix, int $depth = 1): array
    {
        $rules = [
            "{$prefix}.ref" => ['nullable', 'string', 'max:100'],
            "{$prefix}.title" => ['required', 'string', 'max:255'],
            "{$prefix}.node_type" => ['nullable', 'string'],
            "{$prefix}.required_role" => ['nullable', 'string', 'max:100'],
            "{$prefix}.start_offset_days" => ['nullable', 'integer', 'between:0,3650'],
            "{$prefix}.duration_days" => ['nullable', 'integer', 'between:0,3650'],
            "{$prefix}.planned_hours" => ['nullable', 'numeric', 'min:0'],
            "{$prefix}.planned_cost" => ['nullable', 'numeric', 'min:0'],
            "{$prefix}.planned_revenue" => ['nullable', 'numeric', 'min:0'],
            "{$prefix}.children" => ['nullable', 'array'],
        ];

        return $depth >= self::MAX_DEPTH ? $rules : [...$rules, ...$this->nodeRules("{$prefix}.children.*", $depth + 1)];
    }
}
