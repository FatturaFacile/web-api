<?php

declare(strict_types=1);

namespace App\Http\Requests\Ui;

use App\Enums\ThemePreference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantBrandingRequest extends FormRequest
{
    public const HEX_COLOR = '/^#[0-9A-Fa-f]{6}$/';

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Null clears a value; the logo is an https URL or a relative storage path, never a script or data URI.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'brand_primary_color' => ['sometimes', 'nullable', 'string', 'regex:'.self::HEX_COLOR],
            'brand_secondary_color' => ['sometimes', 'nullable', 'string', 'regex:'.self::HEX_COLOR],
            'brand_logo_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'regex:#^(https://[^\s]+|[A-Za-z0-9_][A-Za-z0-9_./-]*)$#'],
            'default_theme' => ['sometimes', 'required', Rule::enum(ThemePreference::class)],
        ];
    }
}
