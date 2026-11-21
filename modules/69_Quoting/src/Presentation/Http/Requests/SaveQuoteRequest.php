<?php

declare(strict_types=1);

namespace Modules\Quoting\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Quoting\Domain\Enums\ConversionTarget;
use Modules\Quoting\Domain\Enums\LineSelectionMode;
use Modules\Quoting\Domain\Enums\QuoteLineType;
use Modules\Quoting\Domain\Enums\SignatureLevel;

final class SaveQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'jurisdiction' => ['sometimes', Rule::in(['CH', 'IT', 'EU'])],
            'locale' => ['sometimes', Rule::in(['it-CH', 'de-CH', 'fr-CH', 'it-IT', 'en'])],
            'contact_id' => ['nullable', 'uuid'],
            'sales_rep_id' => ['nullable', 'uuid'],
            'customer_name' => [$required, 'string', 'max:190'],
            'customer_email' => ['nullable', 'email', 'max:190'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'billing_address' => ['nullable', 'array'],
            'shipping_address' => ['nullable', 'array'],
            'currency' => ['sometimes', Rule::in(['CHF', 'EUR'])],
            'exchange_rate' => ['sometimes', 'numeric', 'gt:0'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'payment_terms' => ['nullable', 'string', 'max:120'],
            'incoterms' => ['nullable', 'string', 'max:12'],
            'cover_letter' => ['nullable', 'string', 'max:20000'],
            'terms_and_conditions' => ['nullable', 'string', 'max:50000'],
            'header_discount_percent' => ['sometimes', 'numeric', 'between:0,100'],
            'min_margin_percent' => ['nullable', 'numeric', 'between:-100,100'],
            'auto_convert' => ['sometimes', 'boolean'],
            'auto_convert_target' => ['nullable', Rule::enum(ConversionTarget::class)],
            'signature_level' => ['sometimes', Rule::enum(SignatureLevel::class)],
            'sections' => ['sometimes', 'array', 'max:100'],
            'sections.*.id' => ['nullable', 'uuid'],
            'sections.*.key' => ['required_with:sections', 'string', 'max:64'],
            'sections.*.title' => ['required_with:sections', 'string', 'max:190'],
            'sections.*.description' => ['nullable', 'string', 'max:5000'],
            'sections.*.page_break_before' => ['sometimes', 'boolean'],
            'sections.*.discount_percent' => ['sometimes', 'numeric', 'between:0,100'],
            'lines' => ['sometimes', 'array', 'max:1000'],
            'lines.*.id' => ['nullable', 'uuid'],
            'lines.*.section_key' => ['nullable', 'string', 'max:64'],
            'lines.*.line_type' => ['required_with:lines', Rule::enum(QuoteLineType::class)],
            'lines.*.selection_mode' => ['sometimes', Rule::enum(LineSelectionMode::class)],
            'lines.*.alternative_group' => ['nullable', 'string', 'max:64', 'required_if:lines.*.selection_mode,alternative_group'],
            'lines.*.is_default_selected' => ['sometimes', 'boolean'],
            'lines.*.product_id' => ['nullable', 'uuid'],
            'lines.*.sku' => ['nullable', 'string', 'max:64'],
            'lines.*.description' => ['required_with:lines', 'string', 'max:500'],
            'lines.*.long_description' => ['nullable', 'string', 'max:5000'],
            'lines.*.uom' => ['nullable', 'string', 'max:16'],
            'lines.*.resource_role' => ['nullable', 'string', 'max:120'],
            'lines.*.quantity' => ['sometimes', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['sometimes', 'numeric', 'min:0'],
            'lines.*.unit_cost' => ['sometimes', 'numeric', 'min:0'],
            'lines.*.discount_percent' => ['sometimes', 'numeric', 'between:0,100'],
            'lines.*.discount_fixed' => ['sometimes', 'numeric', 'min:0'],
            'lines.*.tier_prices' => ['nullable', 'array'],
            'lines.*.tier_prices.*.min_qty' => ['required_with:lines.*.tier_prices', 'numeric', 'min:0'],
            'lines.*.tier_prices.*.unit_price' => ['required_with:lines.*.tier_prices', 'numeric', 'min:0'],
            'lines.*.tax_rate' => ['sometimes', 'numeric', 'between:0,100'],
        ];
    }
}
