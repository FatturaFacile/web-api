<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of an operation confirmation: actual minutes, good and scrapped quantities.
 */
final class ConfirmOperationRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'setup_minutes' => ['nullable', ...self::Minutes],
            'run_minutes' => ['nullable', ...self::Minutes],
            'labor_minutes' => ['nullable', ...self::Minutes],
            'downtime_minutes' => ['nullable', ...self::Minutes],
            'good_quantity' => ['nullable', ...self::Quantity],
            'scrap_quantity' => ['nullable', ...self::Quantity],
            'scrap_reason' => ['nullable', 'required_with:scrap_quantity', 'string', 'max:255'],
            'is_final' => ['nullable', 'boolean'],
            'posting_date' => ['nullable', 'date'],
        ];
    }
}
