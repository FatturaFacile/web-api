<?php

declare(strict_types=1);

namespace Modules\Rebates\Http\Requests;

/**
 * Runs the periodic accrual of a supplier rebate agreement up to the end of the period (today by default).
 */
final class AccrualRunRequest extends RebatesFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'period_end' => ['nullable', 'date_format:Y-m-d'],
            'period_start' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:period_end'],
        ];
    }
}
