<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of a dated capacity exception of a work centre (0 hours for a closure).
 */
final class StoreCalendarExceptionRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'calendar_date' => ['required', 'date'],
            'available_hours' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:24'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
