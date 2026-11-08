<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the input of the update ai setting operation (authorization is decided by module.access).
 */
final class UpdateAiSettingRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['external_providers_enabled' => ['required', 'boolean']];
    }
}
