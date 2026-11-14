<?php

declare(strict_types=1);

namespace Modules\Sustainability\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sustainability\Support\ActivityCatalog;

/**
 * An emission factor. The code names the factor across its versions and cannot change; scope and category follow from
 * the activity type.
 */
final class EmissionFactorRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('esg_emission_factors', 'code')->where('tenant_id', app(TenantContext::class)->getTenantId())],
            'name' => ['required', 'string', 'max:190'],
            'activity_type' => ['required', Rule::in(ActivityCatalog::types())],
            'unit' => ['required', Rule::in(ActivityCatalog::units())],
            'kg_co2e_per_unit' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'region' => ['nullable', Rule::in(ActivityCatalog::Regions)],
            'source' => ['required', 'string', 'max:190'],
            'source_year' => ['required', 'integer', 'min:1990', 'max:2100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ];

        if (! $this->isMethod('PUT')) {
            return $rules;
        }

        unset($rules['code']);

        return array_map(static fn (array $rule): array => ['sometimes', ...$rule], $rules);
    }
}
