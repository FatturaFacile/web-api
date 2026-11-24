<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A price (quantity break) of a catalogue entry with its validity.
 */
final class StoreSupplierPriceRequest extends FormRequest
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
            'currency' => ['nullable', 'string', 'size:3'],
            'min_quantity' => ['nullable', 'decimal:0,4', 'gt:0'],
            'unit_price' => ['required', 'decimal:0,4', 'min:0'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
        ];
    }
}
