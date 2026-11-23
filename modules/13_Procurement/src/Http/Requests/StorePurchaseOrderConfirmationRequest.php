<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Supplier order confirmation: number, date and, per line, the confirmed quantity, price and delivery date.
 */
final class StorePurchaseOrderConfirmationRequest extends FormRequest
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
            'confirmation_number' => ['required', 'string', 'max:80'],
            'confirmed_at' => ['nullable', 'date'],
            'confirmed_delivery_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['nullable', 'array'],
            'lines.*.purchase_order_line_id' => ['required', 'uuid'],
            'lines.*.confirmed_quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.confirmed_unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.confirmed_delivery_date' => ['nullable', 'date'],
            'lines.*.variance_reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
