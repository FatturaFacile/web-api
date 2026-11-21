<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Presentation\Http\Requests;

use Illuminate\Validation\Rule;

final class DrillDownRequest extends CockpitFiltersRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'period' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'bucket' => ['nullable', Rule::in(['current', '1_30', '31_60', '61_90', 'over_90'])],
            'side' => ['nullable', Rule::in(['receivable', 'payable'])],
            'stage_id' => ['nullable', 'uuid'],
            'outcome' => ['nullable', Rule::in(['won', 'lost'])],
            'contact_id' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
            'team_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'export' => ['nullable', Rule::in(['csv'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function drillSegment(): array
    {
        $segment = [];

        foreach (['period', 'bucket', 'side', 'stage_id', 'outcome', 'contact_id', 'product_id', 'team_id'] as $key) {
            if ($this->filled($key)) {
                $segment[$key] = (string) $this->input($key);
            }
        }

        return $segment;
    }
}
