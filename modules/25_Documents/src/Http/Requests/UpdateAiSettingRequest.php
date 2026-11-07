<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateAiSettingRequest extends FormRequest
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
        return ['external_providers_enabled' => ['required', 'boolean']];
    }
}
