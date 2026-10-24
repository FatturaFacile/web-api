<?php

declare(strict_types=1);

namespace App\Http\Requests\Ecommerce;

use App\Models\EcommerceChannel;
use App\Rules\PublicHttpUrl;
use App\Services\Ecommerce\EcommerceManager;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreEcommerceChannelRequest extends FormRequest
{
    /**
     * Credentials every built-in driver needs; Shopify also needs a shop domain unless `api_url` is given.
     *
     * @var array<string, array<int, string>>
     */
    private const REQUIRED_CREDENTIALS = [
        'woocommerce' => ['consumer_key', 'consumer_secret'],
        'shopify' => ['access_token'],
        'prestashop' => ['api_key'],
    ];

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
            'name' => [$presence, 'string', 'max:100', Rule::unique('ecommerce_channels', 'name')->where('tenant_id', app(TenantContext::class)->getTenantId())->ignore($this->route('id'))],
            'driver' => [$presence, Rule::in(app(EcommerceManager::class)->drivers())],
            'api_url' => ['nullable', 'string', 'max:255', new PublicHttpUrl],
            'credentials' => [$presence, 'array'],
            'credentials.*' => ['nullable', 'string', 'max:500'],
            'webhook_secret' => ['nullable', 'string', 'min:16', 'max:128'],
            'is_active' => ['sometimes', 'boolean'],
            'sync_stock_enabled' => ['sometimes', 'boolean'],
            'sync_orders_enabled' => ['sometimes', 'boolean'],
            'settings' => ['nullable', 'array'],
            'settings.order_status_map' => ['nullable', 'array'],
            'settings.order_status_map.*' => ['string', Rule::in(['draft', 'confirmed', 'in_production', 'shipped', 'completed', 'cancelled'])],
            'settings.location_id' => ['nullable', 'string', 'max:30'],
            'settings.api_version' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'settings.currency' => ['nullable', 'string', 'size:3'],
            'settings.language_id' => ['nullable', 'integer', 'min:1'],
            'settings.category_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * The credentials the driver needs must be present once the stored ones (on update) and the sent ones are merged.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $existing = $this->isMethod('POST') ? null : EcommerceChannel::find($this->route('id'));
                $driver = (string) ($this->input('driver') ?? $existing?->driver);
                $credentials = array_merge($existing?->credentials ?? [], (array) $this->input('credentials', []));
                $apiUrl = $this->has('api_url') ? $this->input('api_url') : $existing?->api_url;

                foreach (self::REQUIRED_CREDENTIALS[$driver] ?? [] as $field) {
                    if (empty($credentials[$field])) {
                        $validator->errors()->add("credentials.{$field}", __('ecommerce.errors.credentials_required', ['field' => $field, 'driver' => $driver]));
                    }
                }

                if ($driver === 'shopify' && empty($apiUrl) && empty($credentials['shop_domain'])) {
                    $validator->errors()->add('credentials.shop_domain', __('ecommerce.errors.credentials_required', ['field' => 'shop_domain', 'driver' => $driver]));
                }

                if (in_array($driver, ['woocommerce', 'prestashop'], true) && empty($apiUrl)) {
                    $validator->errors()->add('api_url', __('ecommerce.errors.missing_configuration', ['field' => 'api_url']));
                }
            },
        ];
    }
}
