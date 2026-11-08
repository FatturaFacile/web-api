<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of the weekly calendar of a work centre: gross hours per ISO weekday (1 = Monday), each weekday once.
 */
final class SetWorkCenterCalendarRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'days' => ['required', 'array', 'min:1', 'max:7'],
            'days.*.weekday' => ['required', 'integer', 'between:1,7', 'distinct'],
            'days.*.available_hours' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:24'],
        ];
    }
}
