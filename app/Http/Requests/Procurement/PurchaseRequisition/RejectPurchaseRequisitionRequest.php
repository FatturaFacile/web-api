<?php

declare(strict_types=1);

namespace App\Http\Requests\Procurement\PurchaseRequisition;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an in-app rejection decision (requiring a comment) on a pending Purchase Requisition.
 */
final class RejectPurchaseRequisitionRequest extends FormRequest
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
            'comment' => ['required', 'string', 'min:3', 'max:2000'],
            'channel' => ['nullable', 'string', 'in:web,mobile'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'comment.required' => __('purchase_requisition.validation.comment_required_for_rejection'),
            'comment.min' => __('purchase_requisition.validation.comment_required_for_rejection'),
            'channel.in' => __('purchase_requisition.validation.invalid_channel'),
        ];
    }
}
