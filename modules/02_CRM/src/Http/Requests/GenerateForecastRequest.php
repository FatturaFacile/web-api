<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class GenerateForecastRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period' => ['required', 'string', 'max:20'],
            'user_id' => ['nullable', 'uuid'],
            'currency' => ['nullable', 'string', 'size:3'],
            'quota_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
