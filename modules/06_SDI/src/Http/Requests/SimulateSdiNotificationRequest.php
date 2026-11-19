<?php

declare(strict_types=1);

namespace Modules\SDI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SDI\Enums\SdiNotificationType;

class SimulateSdiNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(SdiNotificationType::class)],
            'outcome' => ['nullable', Rule::in(['EC01', 'EC02'])],
            'error_code' => ['nullable', 'string', 'max:10'],
            'error_description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
