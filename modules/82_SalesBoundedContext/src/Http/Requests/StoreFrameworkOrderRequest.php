<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreFrameworkOrderRequest extends FormRequest
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
            'order_kind' => ['required', Rule::in(['framework_agreement', 'sales_order'])],
            'framework_order_id' => ['nullable', 'uuid'],
            'dossier_id' => ['nullable', 'uuid'],
            'quote_id' => ['nullable', 'uuid'],
            'order_number' => ['nullable', 'string', 'max:40'],
            'currency' => ['nullable', 'string', 'size:3'],
            'ceiling_amount' => ['nullable', 'numeric', 'min:0'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date'],
            'atp_qty' => ['nullable', 'numeric', 'min:0'],
            'ctp_qty' => ['nullable', 'numeric', 'min:0'],
            'requested_qty' => ['nullable', 'numeric', 'min:0'],
            'shipped_qty' => ['nullable', 'numeric', 'min:0'],
            'total_amount' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['nullable', 'array'],
        ];
    }
}
