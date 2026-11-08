<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of a job card completion: good and scrapped units, optional setup and labour minutes.
 */
final class CompleteExecutionRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'good_units' => ['required', ...self::Quantity],
            'scrap_units' => ['nullable', ...self::Quantity],
            'scrap_reason' => ['nullable', 'string', 'max:255'],
            'setup_minutes' => ['nullable', ...self::Minutes],
            'labor_minutes' => ['nullable', ...self::Minutes],
            'is_final' => ['nullable', 'boolean'],
            'posting_date' => ['nullable', 'date'],
        ];
    }
}
