<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ApplyCreditNoteRequest extends FormRequest
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
            'target_invoice_id' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
        ];
    }

    /**
     * The amount as a decimal string, so it never goes through a float.
     */
    public function amount(): string
    {
        return trim((string) $this->validated('amount'));
    }
}
