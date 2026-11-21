<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Presentation\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AnalyticsDss\Domain\ValueObjects\DssFilter;

/**
 * The global strategic filter shared by every DSS endpoint. Legal entities and cost centers must belong to the active tenant.
 */
class DssFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = (string) app(TenantContext::class)->getTenantId();

        return [
            'legal_entity_ids' => ['nullable', 'array', 'max:50'],
            'legal_entity_ids.*' => ['uuid', Rule::exists('companies', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'cost_center_ids' => ['nullable', 'array', 'max:200'],
            'cost_center_ids.*' => ['uuid', Rule::exists('cost_centers', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'currency' => ['nullable', Rule::in(['CHF', 'EUR'])],
            'period_preset' => ['nullable', Rule::in(DssFilter::Presets)],
            'date_from' => ['nullable', 'date_format:Y-m-d', 'required_if:period_preset,CUSTOM'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'required_if:period_preset,CUSTOM', 'after_or_equal:date_from'],
            'comparison_baseline' => ['nullable', Rule::in(DssFilter::Baselines)],
            'as_of' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * The validated filter. Dashboards without a given value use CHF, year to date and the prior year.
     */
    public function toFilter(string $tenantId): DssFilter
    {
        $data = $this->validated();

        return new DssFilter(
            tenantId: $tenantId,
            legalEntityIds: array_values(array_map('strval', $data['legal_entity_ids'] ?? [])),
            costCenterIds: array_values(array_map('strval', $data['cost_center_ids'] ?? [])),
            currency: (string) ($data['currency'] ?? 'CHF'),
            periodPreset: (string) ($data['period_preset'] ?? 'YTD'),
            comparisonBaseline: (string) ($data['comparison_baseline'] ?? 'PRIOR_YEAR'),
            asOf: (string) ($data['as_of'] ?? ''),
            customFrom: $data['date_from'] ?? null,
            customTo: $data['date_to'] ?? null,
        );
    }
}
