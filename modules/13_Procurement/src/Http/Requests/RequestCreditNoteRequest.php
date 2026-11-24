<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Asks the supplier for a credit note for the variance of a blocked invoice.
 */
final class RequestCreditNoteRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:2000'],
            'expected_credit_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
