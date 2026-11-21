<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class GenerateSepaRequest extends FormRequest
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
            'debtor_name' => ['required', 'string', 'max:70'],
            'debtor_iban' => ['required', 'string', 'max:34'],
            'debtor_bic' => ['nullable', 'string', 'regex:/^[A-Za-z]{6}[A-Za-z0-9]{2}([A-Za-z0-9]{3})?$/'],
            'execution_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }
}
