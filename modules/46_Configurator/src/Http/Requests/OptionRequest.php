<?php

declare(strict_types=1);

namespace Modules\Configurator\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Configurator\Models\ConfiguratorOption;
use Modules\Configurator\Models\ConfiguratorOptionGroup;

/**
 * An option of a group, on creation (POST, the route names the group) or change (PUT, the route names the option).
 */
final class OptionRequest extends FormRequest
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
        $modelId = $creating
            ? ConfiguratorOptionGroup::query()->whereKey($this->route('id'))->value('model_id')
            : ConfiguratorOption::query()->whereKey($this->route('id'))->value('model_id');

        return [
            'code' => [$presence, 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('cfg_options', 'code')->where('tenant_id', app(TenantContext::class)->getTenantId())->where('model_id', $modelId)->whereNull('deleted_at')->ignore($creating ? null : $this->route('id'))],
            'name' => [$presence, 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price_delta' => ['sometimes', 'numeric', 'min:-99999999999', 'max:99999999999'],
            'price_multiplier' => ['sometimes', 'numeric', 'gt:0', 'max:1000'],
            'cost' => ['sometimes', 'numeric', 'min:0', 'max:99999999999'],
            'product_id' => ['nullable', 'uuid', $this->tenantExists('products')],
            'price_from_product' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }

    /**
     * The payload to store: a price from the product only when a product is linked.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        if (array_key_exists('product_id', $data) && $data['product_id'] === null) {
            $data['price_from_product'] = false;
        }

        return $data;
    }
}
