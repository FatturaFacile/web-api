<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class EligibilityCheckRequest extends FormRequest
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
            'contact_id' => ['required', 'uuid'],
            'invoice_id' => ['nullable', 'uuid'],
            'delivery_note_id' => ['nullable', 'uuid'],
            'reason_code' => ['required', 'string', 'max:40'],
            'requested_on' => ['nullable', 'date_format:Y-m-d'],
            'restocking_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.invoice_item_id' => ['nullable', 'uuid'],
            'lines.*.delivery_note_item_id' => ['nullable', 'uuid'],
            'lines.*.product_id' => ['nullable', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
