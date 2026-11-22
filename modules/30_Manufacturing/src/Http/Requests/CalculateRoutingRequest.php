<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of the lead time and standard cost simulation of a routing.
 */
final class CalculateRoutingRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['nullable', ...self::PositiveQuantity],
            'include_overlap' => ['nullable', 'boolean'],
            'start_datetime' => ['nullable', 'date'],
        ];
    }
}
