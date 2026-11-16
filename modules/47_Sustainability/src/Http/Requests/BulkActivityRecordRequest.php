<?php

declare(strict_types=1);

namespace Modules\Sustainability\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sustainability\Support\ActivityCatalog;

/**
 * Several lines of activity data recorded together (a meter file, a month of fuel cards).
 */
final class BulkActivityRecordRequest extends FormRequest
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
        return [
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.emission_factor_id' => ['nullable', 'uuid', $this->tenantOwns('esg_emission_factors')],
            'lines.*.activity_type' => ['required_without:lines.*.emission_factor_id', 'nullable', Rule::in(ActivityCatalog::types())],
            'lines.*.region' => ['nullable', Rule::in(ActivityCatalog::Regions)],
            'lines.*.activity_date' => ['required', 'date_format:Y-m-d'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            'lines.*.unit' => ['required', Rule::in(ActivityCatalog::units())],
            'lines.*.data_quality' => ['nullable', Rule::in(ActivityCatalog::DataQualities)],
            'lines.*.site' => ['nullable', 'string', 'max:120'],
            'lines.*.reference' => ['nullable', 'string', 'max:120'],
            'lines.*.source_type' => ['nullable', 'string', 'max:60'],
            'lines.*.source_id' => ['nullable', 'uuid'],
            'lines.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
