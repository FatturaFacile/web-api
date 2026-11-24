<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Checks whether a sale without VAT is covered by a declaration of intent.
 */
final class PlafondCheckRequest extends FormRequest
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
            'contact_id' => ['required', 'uuid'],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'declaration_id' => ['nullable', 'uuid'],
        ];
    }
}
