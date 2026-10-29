<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ExecuteThreeWayMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'purchase_order_id' => ['required', 'uuid'],
            'purchase_receipt_id' => ['required', 'uuid'],
            'passive_invoice_id' => ['required', 'uuid'],
            'invoice_total_amount' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
