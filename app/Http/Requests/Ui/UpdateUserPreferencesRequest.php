<?php

declare(strict_types=1);

namespace App\Http\Requests\Ui;

use App\Enums\ThemePreference;
use App\Enums\UiDensity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'theme_preference' => ['sometimes', 'required', Rule::enum(ThemePreference::class)],
            'ui_density' => ['sometimes', 'required', Rule::enum(UiDensity::class)],
            'reduced_motion' => ['sometimes', 'nullable', 'boolean'],
        ];
    }
}
