<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Outcome of the quality inspection of receipt lines: the quantity released from quarantine.
 */
final class InspectGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'results' => ['required', 'array', 'min:1'],
            'results.*.purchase_receipt_line_id' => ['required', 'uuid', 'distinct'],
            'results.*.quantity_released' => ['required', 'decimal:0,4', 'min:0'],
            'results.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
