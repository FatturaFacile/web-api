<?php

declare(strict_types=1);

namespace Modules\Sustainability\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sustainability\Models\EsgKpiDefinition;

/**
 * An ESG indicator. The code is unique in the company and cannot change.
 */
final class KpiDefinitionRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware and the policy.
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
        $rules = [
            'code' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/', Rule::unique('esg_kpi_definitions', 'code')->where('tenant_id', app(TenantContext::class)->getTenantId())],
            'name' => ['required', 'string', 'max:190'],
            'pillar' => ['required', Rule::in(EsgKpiDefinition::Pillars)],
            'unit' => ['required', 'string', 'max:20'],
            'direction' => ['nullable', Rule::in(EsgKpiDefinition::Directions)],
            'esrs_reference' => ['nullable', 'string', 'max:30'],
            'target_value' => ['nullable', 'numeric', 'min:-999999999999', 'max:999999999999'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ];

        if (! $this->isMethod('PUT')) {
            return $rules;
        }

        unset($rules['code']);

        return array_map(static fn (array $rule): array => ['sometimes', ...$rule], $rules);
    }
}
