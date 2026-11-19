<?php

declare(strict_types=1);

namespace Modules\Controlling\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Controlling\Services\MultidimensionalMarginService;

/**
 * Validation of the MarginQueryRequest payload; every reference is limited to the active company.
 */
final class MarginQueryRequest extends ControllingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenant = $this->tenantId();

        return [
            'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'margin_tier' => ['nullable', Rule::in(MultidimensionalMarginService::Tiers)],
            'compare_mode' => ['nullable', Rule::in(MultidimensionalMarginService::CompareModes)],
            'product_id' => ['nullable', 'uuid'], 'product_family_id' => ['nullable', 'uuid'], 'customer_id' => ['nullable', 'uuid'],
            'country_code' => ['nullable', 'string', 'size:2'], 'region_code' => ['nullable', 'string', 'max:16'], 'province_code' => ['nullable', 'string', 'max:16'],
            'sales_agent_id' => ['nullable', 'uuid'], 'production_department_id' => ['nullable', 'uuid'], 'cost_center_id' => ['nullable', 'uuid'], 'job_id' => ['nullable', 'uuid'],
            'group_by' => ['nullable', Rule::in(MultidimensionalMarginService::Dimensions)],
            'row_dimension' => ['nullable', Rule::in(MultidimensionalMarginService::Dimensions)], 'col_dimension' => ['nullable', Rule::in(MultidimensionalMarginService::Dimensions)],
            'measure' => ['nullable', Rule::in(['net_revenue', 'gross_margin', 'commercial_margin', 'full_industrial_margin', 'margin_percent'])],
            'drill_path' => ['nullable', 'array', 'max:8'], 'drill_path.*.dimension' => ['required', Rule::in(MultidimensionalMarginService::Dimensions)], 'drill_path.*.value' => ['required', 'string', 'max:64'],
            'sort' => ['nullable', 'string', 'max:40'], 'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'between:1,500'],
            'format' => ['nullable', Rule::in(['csv', 'xlsx'])],
        ];
    }
}
