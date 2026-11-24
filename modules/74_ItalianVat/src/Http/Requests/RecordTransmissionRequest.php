<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Protocol of the receipt of the Agenzia delle Entrate.
 */
final class RecordTransmissionRequest extends FormRequest
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
            'protocol' => ['required', 'string', 'regex:/^\d{17}([-\/]\d{6})?$/'],
            'transmitted_at' => ['nullable', 'date'],
        ];
    }
}
