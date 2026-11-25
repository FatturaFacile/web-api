<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreditNoteFiscalCheckRequest extends FormRequest
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
            'customer_return_id' => ['required', 'uuid'],
            'credit_note_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
