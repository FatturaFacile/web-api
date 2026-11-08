<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Analytics\Models\ReportDefinition;
use Modules\Analytics\Services\KpiCatalog;
use Modules\Analytics\Support\ReportPeriod;

/**
 * Validates a saved report definition (access is decided by module.access and the policy of the definition).
 */
final class SaveReportDefinitionRequest extends FormRequest
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
        $ignore = $this->route('id');
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'name' => [
                'required', 'string', 'max:150',
                Rule::unique('analytics_report_definitions', 'name')
                    ->where('tenant_id', $tenantId)
                    ->where('user_id', $this->user()?->id)
                    ->whereNull('deleted_at')
                    ->ignore($ignore),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'kpis' => ['required', 'array', 'min:1', 'max:'.count(KpiCatalog::keys())],
            'kpis.*' => ['string', 'distinct', Rule::in(KpiCatalog::keys())],
            'period_preset' => ['required', Rule::in(ReportDefinition::Presets)],
            'from_date' => ['nullable', 'required_if:period_preset,custom', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'required_if:period_preset,custom', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'compare' => ['nullable', Rule::in([ReportPeriod::CompareNone, ReportPeriod::ComparePreviousPeriod, ReportPeriod::ComparePreviousYear])],
            'is_shared' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Fixed dates make sense only with the custom preset; any other preset resolves its own dates.
     *
     * @return array<string, mixed>
     */
    public function definitionAttributes(): array
    {
        $data = $this->validated();

        if (($data['period_preset'] ?? null) !== 'custom') {
            $data['from_date'] = null;
            $data['to_date'] = null;
        }

        $data['compare'] ??= ReportPeriod::CompareNone;
        $data['is_shared'] = (bool) ($data['is_shared'] ?? false);

        return $data;
    }
}
