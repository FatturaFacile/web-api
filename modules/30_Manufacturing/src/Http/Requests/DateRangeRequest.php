<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of a date window (capacity, KPIs), optionally of one work centre.
 */
final class DateRangeRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'work_center_id' => ['nullable', 'uuid', $this->tenantOwns('mfg_work_centers')],
        ];
    }
}
