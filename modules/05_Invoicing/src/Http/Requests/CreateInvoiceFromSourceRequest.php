<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Invoicing\Http\Requests\Concerns\BillingOverrideRules;

/**
 * What a caller may state when an invoice is generated from a sales order or a delivery note. The customer, the
 * source documents and the number always come from the source document and the numbering service. For an order, `lines`
 * bills only the given quantities of the given order lines (partial billing); without it every open quantity is billed.
 */
final class CreateInvoiceFromSourceRequest extends FormRequest
{
    use BillingOverrideRules, TenantScopedRules;

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
            ...$this->overrideRules(),
            'lines' => ['sometimes', 'array', 'min:1'],
            'lines.*.sales_order_item_id' => ['required_with:lines', 'uuid', $this->tenantOwns('sales_order_items')],
            'lines.*.quantity' => ['required_with:lines', 'numeric', 'gt:0'],
        ];
    }
}
