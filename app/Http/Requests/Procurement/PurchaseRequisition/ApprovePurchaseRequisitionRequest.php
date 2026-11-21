<?php

declare(strict_types=1);

namespace App\Http\Requests\Procurement\PurchaseRequisition;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an in-app approval decision on a pending Purchase Requisition.
 */
final class ApprovePurchaseRequisitionRequest extends FormRequest
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
            'comment' => ['nullable', 'string', 'max:2000'],
            'channel' => ['nullable', 'string', 'in:web,mobile'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'channel.in' => __('purchase_requisition.validation.invalid_channel'),
        ];
    }
}
