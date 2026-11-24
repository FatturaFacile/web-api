<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Procurement\Enums\InvoiceMatchType;
use Modules\Procurement\Enums\ToleranceScope;

/**
 * Creates or updates a tolerance rule of the invoice matching.
 */
final class InvoiceMatchToleranceRuleRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:120'],
            'scope' => ['required', Rule::enum(ToleranceScope::class)],
            'supplier_id' => ['nullable', 'uuid', 'required_if:scope,'.ToleranceScope::Supplier->value],
            'item_category' => ['nullable', 'string', 'max:60', 'required_if:scope,'.ToleranceScope::ItemCategory->value],
            'match_type' => ['nullable', Rule::enum(InvoiceMatchType::class)],
            'priority' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'price_tolerance_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'price_tolerance_amount' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
            'quantity_tolerance_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'quantity_tolerance_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
