<?php

declare(strict_types=1);

namespace Modules\DownPayments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Options of the final settlement invoice. Held earnest money listed in `impute_deposit_ids` is imputed to the price by the same call.
 */
final class FinalSettlementRequest extends FormRequest
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
            'document_type' => ['nullable', 'string', 'in:TD01,TD24'],
            'payment_method' => ['nullable', 'string', 'max:4'],
            'impute_deposit_ids' => ['nullable', 'array', 'max:50'],
            'impute_deposit_ids.*' => ['uuid'],
        ];
    }
}
