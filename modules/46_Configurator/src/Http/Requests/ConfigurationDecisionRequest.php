<?php

declare(strict_types=1);

namespace Modules\Configurator\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The decision on a discount below the minimum margin; a rejection must say why.
 */
final class ConfigurationDecisionRequest extends FormRequest
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
            'decision_notes' => [$this->routeIs('api.v1.configurator.configurations.reject') ? 'required' : 'nullable', 'string', 'max:2000'],
        ];
    }
}
