<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SubmitCSATRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'score' => ['required', 'integer', 'min:1', 'max:5'],
            'feedback' => ['nullable', 'string'],
            'ticket_id' => ['nullable', 'uuid'],
            'work_order_id' => ['nullable', 'uuid'],
            'customer_id' => ['nullable', 'uuid'],
            'respondent_email' => ['nullable', 'email'],
        ];
    }
}
