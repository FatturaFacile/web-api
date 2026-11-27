<?php

declare(strict_types=1);

namespace Modules\DownPayments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Emits an installment of the plan: the advance invoice, the pro-forma request or the deposit receipt it states.
 */
final class EmitInstallmentRequest extends FormRequest
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
            'date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'payment_date' => ['nullable', 'date'],
            'payment_account' => ['nullable', 'string', 'in:bank,cash'],
        ];
    }
}
