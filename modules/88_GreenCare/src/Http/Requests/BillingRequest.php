<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Requests;

/**
 * Selection of what a contract bills in a run (preview and invoice generation).
 */
final class BillingRequest extends GreenCareRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'include_installment' => ['sometimes', 'boolean'],
            'include_snow_standby' => ['sometimes', 'boolean'],
            'include_extra_passages' => ['sometimes', 'boolean'],
            'include_time_and_material' => ['sometimes', 'boolean'],
            'include_waste' => ['sometimes', 'boolean'],
        ];
    }
}
