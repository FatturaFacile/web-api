<?php

declare(strict_types=1);

namespace App\Http\Requests\Procurement\Rfq;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates external supplier bid submission or draft save via magic-link token.
 */
final class SubmitExternalBidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines');
        if (is_string($lines) && $lines !== '') {
            $decoded = json_decode($lines, true);
            if (is_array($decoded)) {
                $this->merge(['lines' => $decoded]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['nullable', 'string', 'in:draft,submit'],
            'supplier_quote_reference' => ['nullable', 'string', 'max:100'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'currency' => ['nullable', 'string', 'size:3'],
            'exchange_rate_to_rfq' => ['nullable', 'numeric', 'gt:0'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'incoterms' => ['nullable', 'string', 'max:16'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'valid_until' => ['nullable', 'date_format:Y-m-d'],
            'general_notes' => ['nullable', 'string', 'max:4000'],
            'quote_attachment' => ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.rfq_line_id' => ['required', 'uuid'],
            'lines.*.is_no_bid' => ['nullable', 'boolean'],
            'lines.*.quoted_quantity' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'lines.*.promised_delivery_date' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.supplier_notes' => ['nullable', 'string', 'max:1000'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
