<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of downtime reported on a job card.
 */
final class RecordDowntimeRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'downtime_minutes' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:100000'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
