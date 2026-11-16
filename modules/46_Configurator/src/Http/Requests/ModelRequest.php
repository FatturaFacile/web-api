<?php

declare(strict_types=1);

namespace Modules\Configurator\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A configurable model, on creation (POST) or change (PUT).
 */
final class ModelRequest extends FormRequest
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
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'code' => [$presence, 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('cfg_models', 'code')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at')->ignore($this->route('id'))],
            'name' => [$presence, 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:5000'],
            'base_product_id' => ['nullable', 'uuid', $this->tenantExists('products')],
            'base_price_from_product' => ['sometimes', 'boolean'],
            'base_price' => ['sometimes', 'numeric', 'min:0', 'max:99999999999'],
            'base_cost' => ['sometimes', 'numeric', 'min:0', 'max:99999999999'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'tax_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'min_margin_percent' => ['nullable', 'numeric', 'min:0', 'max:99.99'],
            'volume_breaks' => ['nullable', 'array', 'max:20'],
            'volume_breaks.*.min_quantity' => ['required', 'numeric', 'gt:0', 'distinct'],
            'volume_breaks.*.discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /**
     * The payload to store: the currency in upper case and a price from the linked product only when there is one.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        if (isset($data['currency'])) {
            $data['currency'] = strtoupper((string) $data['currency']);
        }

        if (array_key_exists('base_product_id', $data) && $data['base_product_id'] === null) {
            $data['base_price_from_product'] = false;
        }

        return $data;
    }
}
