<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creates or updates a draft blanket agreement. On update the customer and the type stay as they are.
 */
final class BlanketAgreementRequest extends FormRequest
{
    use TenantScopedRules;

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

        return [
            'contact_id' => [Rule::requiredIf($creating), 'uuid', $this->tenantOwns('contacts')],
            'agreement_type' => [Rule::requiredIf($creating), Rule::in(['quantity_based', 'value_based'])],
            'valid_from' => [Rule::requiredIf($creating), 'date'],
            'valid_to' => [Rule::requiredIf($creating), 'date', 'after_or_equal:valid_from'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'pricing_lock_mode' => ['nullable', Rule::in(['fixed_contract_price', 'spot_price_with_discount'])],
            'over_release_tolerance_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'total_committed_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_terms' => ['nullable', 'string', 'max:100'],
            'incoterms' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string'],
            'lines' => [Rule::requiredIf($creating), 'array', 'min:1'],
            'lines.*.product_id' => ['nullable', 'uuid', $this->tenantExists('products')],
            'lines.*.description' => ['required_with:lines', 'string', 'max:255'],
            'lines.*.unit_of_measure' => ['nullable', 'string', 'max:20'],
            'lines.*.target_quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['required_with:lines', 'numeric', 'min:0'],
            'lines.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.tax_rate' => ['required_with:lines', 'numeric', 'min:0', 'max:100'],
            'lines.*.target_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.delivery_schedule' => ['nullable', 'array'],
        ];
    }
}
