<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of new planned dates of a production order.
 */
final class RescheduleRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'planned_start_date' => ['required', 'date'],
            'planned_end_date' => ['required', 'date', 'after_or_equal:planned_start_date'],
        ];
    }
}
