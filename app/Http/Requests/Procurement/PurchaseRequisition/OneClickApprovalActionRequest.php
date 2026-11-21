<?php

declare(strict_types=1);

namespace App\Http\Requests\Procurement\PurchaseRequisition;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates signed single-use 1-click Email/Push approval or rejection actions.
 */
final class OneClickApprovalActionRequest extends FormRequest
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
            'token' => ['required', 'string', 'min:20', 'max:4096'],
            'action' => ['required', 'string', 'in:approve,reject'],
            'comment' => ['required_if:action,reject', 'nullable', 'string', 'min:3', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.required' => __('purchase_requisition.validation.token_required'),
            'action.required' => __('purchase_requisition.validation.action_invalid'),
            'action.in' => __('purchase_requisition.validation.action_invalid'),
            'comment.required_if' => __('purchase_requisition.validation.comment_required_for_rejection'),
        ];
    }
}
