<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateConsentRequest extends FormRequest
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
            'marketing_opt_in' => ['sometimes', 'boolean'],
            'profiling_opt_in' => ['sometimes', 'boolean'],
            'soft_opt_in_eligible' => ['sometimes', 'boolean'],
            'double_opt_in_confirmed' => ['sometimes', 'boolean'],
            'channel_preferences' => ['sometimes', 'array'],
            'channel_preferences.email' => ['sometimes', 'boolean'],
            'channel_preferences.whatsapp' => ['sometimes', 'boolean'],
            'channel_preferences.sms' => ['sometimes', 'boolean'],
            'locale' => ['sometimes', 'in:it,de,fr,en'],
            'source' => ['nullable', 'string', 'max:60'],
        ];
    }
}
