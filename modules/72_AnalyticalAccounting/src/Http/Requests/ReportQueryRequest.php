<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Validation of the filters of the contribution margin and direct-vs-apportioned reports.
 */
final class ReportQueryRequest extends AnalyticalFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'cost_center_id' => ['nullable', 'uuid', $this->owned('analytical_centers')],
            'profit_center_id' => ['nullable', 'uuid', $this->owned('analytical_centers')],
            'center_id' => ['nullable', 'uuid', $this->owned('analytical_centers')],
            'project_id' => ['nullable', 'uuid'],
            'product_line_id' => ['nullable', 'uuid'],
            'include_descendants' => ['nullable', 'boolean'],
            'group_by' => ['nullable', Rule::in(['cost_center', 'profit_center', 'project', 'product_line'])],
            'format' => ['nullable', Rule::in(['json', 'csv'])],
        ];
    }
}
