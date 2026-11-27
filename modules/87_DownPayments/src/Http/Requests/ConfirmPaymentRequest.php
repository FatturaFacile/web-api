<?php

declare(strict_types=1);

namespace Modules\DownPayments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The payment of a pro-forma advance request or of a deposit receipt came in (the manual twin of the Treasury reconciliation).
 */
final class ConfirmPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization is enforced by the policy in the controller
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'paid_at' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['nullable', 'string', 'max:4'],
            'payment_account' => ['nullable', 'string', 'in:bank,cash'],
        ];
    }
}
