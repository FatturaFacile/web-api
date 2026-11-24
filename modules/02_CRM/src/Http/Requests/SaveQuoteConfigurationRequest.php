<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

/**
 * A CPQ quote configuration. With a product configuration of the configurator the unit price comes from it; otherwise
 * unit_price is required. Prices, discounts and the approval are computed by the service, never taken from the payload.
 */
final class SaveQuoteConfigurationRequest extends CrmExtensionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $needsPrice = $this->isMethod('POST') && $this->input('product_configuration_id') === null;

        return $this->forMethod([
            'name' => ['required', 'string', 'max:200'],
            'opportunity_id' => ['nullable', 'uuid', $this->ownRow('crm_opportunities')],
            'product_configuration_id' => ['nullable', 'uuid'],
            'partner_id' => ['nullable', 'uuid', $this->ownRow('crm_partners')],
            'quantity' => [$this->isMethod('POST') && $this->input('product_configuration_id') !== null ? 'nullable' : 'required', 'numeric', 'gt:0', 'max:999999999'],
            'unit_price' => [$needsPrice ? 'required' : 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'extra_discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
        ]);
    }

    /**
     * Fields the client did not send stay out so the service defaults and the stored values apply.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return array_filter($this->validated(), static fn (mixed $value): bool => $value !== null);
    }
}
