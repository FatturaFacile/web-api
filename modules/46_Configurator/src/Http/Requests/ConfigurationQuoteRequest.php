<?php

declare(strict_types=1);

namespace Modules\Configurator\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Terms of the quote a configuration is turned into.
 */
final class ConfigurationQuoteRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware and the policy.
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
        return [
            'date' => ['nullable', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
