<?php

declare(strict_types=1);

namespace Modules\Sustainability\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sustainability\Support\ActivityCatalog;

/**
 * The value of one indicator in one reporting period (entered again, it replaces the previous value).
 */
final class KpiValueRequest extends FormRequest
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
            'kpi_definition_id' => ['required', 'uuid', $this->tenantOwns('esg_kpi_definitions')],
            'reporting_period_id' => ['required', 'uuid', $this->tenantOwns('esg_reporting_periods')],
            'value' => ['required', 'numeric', 'min:-999999999999', 'max:999999999999'],
            'data_quality' => ['nullable', Rule::in(ActivityCatalog::DataQualities)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
