<?php

declare(strict_types=1);

namespace Modules\Configurator\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The selections and the commercial terms of a configuration: the live evaluation of a model (POST, the route names the
 * model), the creation of a configuration (POST, `model_id`) and its change (PUT). A selection answers one group: the
 * chosen option codes of a single, multiple or boolean group, the number of a range group.
 */
final class ConfigurationRequest extends FormRequest
{
    use TenantScopedRules;

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
        $creating = $this->routeIs('api.v1.configurator.configurations.store');
        $changing = $this->isMethod('PUT');

        return [
            'model_id' => [$creating ? 'required' : 'prohibited', 'uuid', $this->tenantExists('cfg_models')],
            'name' => ['nullable', 'string', 'max:190'],
            'selections' => [$changing ? 'sometimes' : 'present', 'array', 'max:200'],
            'selections.*.group' => ['required', 'string', 'max:40', 'distinct'],
            'selections.*.options' => ['sometimes', 'array', 'max:200'],
            'selections.*.options.*' => ['string', 'max:40'],
            'selections.*.value' => ['nullable', 'numeric'],
            'quantity' => ['sometimes', 'numeric', 'gt:0', 'max:99999999'],
            'contact_id' => ['nullable', 'uuid', $this->tenantExists('contacts')],
            'price_list_id' => ['nullable', 'uuid', $this->tenantExists('price_lists')],
            'discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'apply_defaults' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();
        unset($data['model_id']);

        return $data;
    }
}
