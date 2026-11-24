<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of processed quantity received back from a subcontractor.
 */
final class ReceiveSubcontractRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'good_quantity' => ['required', ...self::Quantity],
            'scrap_quantity' => ['nullable', ...self::Quantity],
            'posting_date' => ['nullable', 'date'],
        ];
    }
}
