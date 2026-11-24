<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PreviewTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Role access to the module is enforced by the module.access middleware of the route group.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'locale' => ['required', 'in:it,de,fr,en'],
            'variant' => ['nullable', 'string', 'max:20'],
            'template' => ['required', 'array'],
        ];
    }
}
