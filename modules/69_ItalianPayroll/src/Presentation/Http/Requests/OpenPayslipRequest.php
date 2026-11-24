<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class OpenPayslipRequest extends FormRequest
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
            'document_kind' => ['required', Rule::in(['cedolino', 'cu'])],
            'biometric_confirmed' => ['nullable', 'boolean'],
        ];
    }
}
