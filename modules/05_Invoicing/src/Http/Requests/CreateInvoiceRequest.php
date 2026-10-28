<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateInvoiceRequest extends FormRequest
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
            'contact_id' => ['required', 'uuid'],
            'invoice_number' => ['nullable', 'string', 'max:50'],
            'invoice_type' => ['nullable', 'string', 'in:standard,credit_note,proforma'],
            'date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0.000001'],
            'jurisdiction' => ['nullable', 'string', 'in:IT,CH'],
            'split_payment' => ['nullable', 'boolean'],
            'reverse_charge' => ['nullable', 'boolean'],
            'payment_method' => ['nullable', 'string', 'max:4'],
            'payment_terms' => ['nullable', 'string', 'max:4'],
            'bank_name' => ['nullable', 'string'],
            'bank_iban' => ['nullable', 'string', 'max:34'],
            'sales_order_id' => ['nullable', 'uuid'],
            'delivery_note_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'project_milestone_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sales_order_item_id' => ['nullable', 'uuid'],
            'items.*.delivery_note_item_id' => ['nullable', 'uuid'],
            'items.*.product_id' => ['nullable', 'uuid'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.revenue_account_id' => ['nullable', 'uuid'],
            'items.*.tax_account_id' => ['nullable', 'uuid'],
            'items.*.uom' => ['nullable', 'string', 'max:20'],
        ];
    }
}
