<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of a component issue to a production order.
 */
final class IssueMaterialsRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'posting_date' => ['nullable', 'date'],
            'allow_over_issue' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', ...self::PositiveQuantity],
            'lines.*.lot_id' => ['nullable', 'uuid', $this->tenantOwns('stock_lots')],
        ];
    }
}
