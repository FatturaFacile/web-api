<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveOnboardingTemplateRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'tasks' => ['required', 'array', 'min:1', 'max:200'],
            'tasks.*.title' => ['required', 'string', 'max:180'],
            'tasks.*.due_after_days' => ['nullable', 'integer', 'between:-90,365'],
        ];
    }
}
