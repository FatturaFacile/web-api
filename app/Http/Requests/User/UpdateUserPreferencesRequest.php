<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Interface preferences of the signed-in user; a field that is absent keeps its value.
 */
class UpdateUserPreferencesRequest extends FormRequest
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
            'theme_preference' => ['sometimes', 'string', Rule::in(['light', 'dark', 'system'])],
            'ui_density' => ['sometimes', 'string', Rule::in(['compact', 'comfortable', 'spacious'])],
            // null follows the operating system setting.
            'reduced_motion' => ['sometimes', 'nullable', 'boolean'],
        ];
    }
}
