<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveGoalKeyResultRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'metric' => [$required, 'string', 'max:180'],
            'unit' => ['nullable', 'string', 'max:30'],
            'start_value' => ['nullable', 'numeric', 'between:-99999999999999,99999999999999'],
            'target_value' => [$required, 'numeric', 'between:-99999999999999,99999999999999'],
            'current_value' => ['nullable', 'numeric', 'between:-99999999999999,99999999999999'],
        ];
    }
}
