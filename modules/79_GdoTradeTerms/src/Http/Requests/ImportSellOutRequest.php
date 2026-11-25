<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ImportSellOutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'source' => ['nullable', Rule::in(['manual', 'import'])],
            'rows' => ['required', 'array', 'min:1', 'max:2000'],
            'rows.*.delivery_point_id' => ['nullable', 'uuid'],
            'rows.*.product_id' => ['required', 'uuid'],
            'rows.*.period_start' => ['required', 'date'],
            'rows.*.period_end' => ['required', 'date', 'after_or_equal:rows.*.period_start'],
            'rows.*.quantity' => ['required', 'numeric'],
            'rows.*.amount' => ['required', 'numeric'],
        ];
    }
}
