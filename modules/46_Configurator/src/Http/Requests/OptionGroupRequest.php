<?php

declare(strict_types=1);

namespace Modules\Configurator\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Configurator\Models\ConfiguratorOptionGroup;

/**
 * An option group of a model, on creation (POST, the route names the model) or change (PUT, the route names the group).
 */
final class OptionGroupRequest extends FormRequest
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
        $creating = $this->isMethod('POST');
        $presence = $creating ? 'required' : 'sometimes';
        $modelId = $creating ? $this->route('id') : ConfiguratorOptionGroup::query()->whereKey($this->route('id'))->value('model_id');

        return [
            'code' => [$presence, 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('cfg_option_groups', 'code')->where('tenant_id', app(TenantContext::class)->getTenantId())->where('model_id', $modelId)->whereNull('deleted_at')->ignore($creating ? null : $this->route('id'))],
            'name' => [$presence, 'string', 'max:190'],
            'type' => [$presence, Rule::in(ConfiguratorOptionGroup::Types)],
            'is_required' => ['sometimes', 'boolean'],
            'min_select' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'max_select' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'range_min' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'range_max' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'range_step' => ['nullable', 'numeric', 'gt:0', 'max:99999999999'],
            'default_value' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'unit' => ['nullable', 'string', 'max:20'],
            'unit_price_delta' => ['sometimes', 'numeric', 'min:-99999999999', 'max:99999999999'],
            'unit_cost' => ['sometimes', 'numeric', 'min:0', 'max:99999999999'],
            'product_id' => ['nullable', 'uuid', $this->tenantExists('products')],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
