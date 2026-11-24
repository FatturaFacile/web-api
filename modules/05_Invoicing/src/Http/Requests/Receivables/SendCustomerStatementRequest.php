<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests\Receivables;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Dispatch of a customer statement by email: period, recipient (the customer email when omitted) and locale.
 */
final class SendCustomerStatementRequest extends FormRequest
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
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'recipient' => ['nullable', 'email:rfc', 'max:255'],
            'locale' => ['nullable', Rule::in(['it', 'de', 'fr', 'en'])],
        ];
    }
}
