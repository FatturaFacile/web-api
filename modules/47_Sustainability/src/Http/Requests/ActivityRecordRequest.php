<?php

declare(strict_types=1);

namespace Modules\Sustainability\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sustainability\Support\ActivityCatalog;

/**
 * One line of activity data. Either the emission factor is named, or the activity type (and optionally the region)
 * selects the most recent active factor.
 */
final class ActivityRecordRequest extends FormRequest
{
    use TenantScopedRules;

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
            'emission_factor_id' => ['nullable', 'uuid', $this->tenantOwns('esg_emission_factors')],
            'activity_type' => ['required_without:emission_factor_id', 'nullable', Rule::in(ActivityCatalog::types())],
            'region' => ['nullable', Rule::in(ActivityCatalog::Regions)],
            'activity_date' => ['required', 'date_format:Y-m-d'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            'unit' => ['required', Rule::in(ActivityCatalog::units())],
            'data_quality' => ['nullable', Rule::in(ActivityCatalog::DataQualities)],
            'site' => ['nullable', 'string', 'max:120'],
            'reference' => ['nullable', 'string', 'max:120'],
            'source_type' => ['nullable', 'string', 'max:60'],
            'source_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];

        return $this->isMethod('PUT') ? array_map(static fn (array $rule): array => ['sometimes', ...$rule], $rules) : $rules;
    }
}
