<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payment made with the F24.
 */
final class F24PaymentRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'gt:0'],
            'paid_at' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:40'],
        ];
    }
}
