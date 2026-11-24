<?php

declare(strict_types=1);

namespace App\Http\Requests\Procurement\PurchaseRequisition;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates submission of a Purchase Requisition to budget verification and approval routing.
 */
final class SubmitPurchaseRequisitionRequest extends FormRequest
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
            'channel' => ['nullable', 'string', 'in:web,mobile'],
            'acknowledge_budget_warning' => ['nullable', 'boolean'],
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
