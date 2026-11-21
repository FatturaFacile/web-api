<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SuggestCodingRequest extends FormRequest
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
            'description' => ['required', 'string', 'min:3', 'max:2000'],
            'amount' => ['nullable', 'numeric'],
            'counterparty' => ['nullable', 'string', 'max:255'],
            'source_type' => ['required', Rule::in(['bank_transaction', 'expense_receipt', 'document'])],
            'source_reference' => ['nullable', 'string', 'max:120'],
        ];
    }
}
