<?php

declare(strict_types=1);

namespace Modules\Transportation\Http\Requests;

/**
 * Payload of a carrier invoice with its lines. A PUT only validates the fields it sends; the lines it sends replace the
 * existing ones. The invoice number is unique per carrier.
 */
final class FreightInvoiceRequest extends TransportationFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'invoice_number' => ['required', 'string', 'max:60', $this->uniqueIn('tms_freight_invoices', 'invoice_number', ['carrier_id'])],
            'invoice_date' => ['required', 'date_format:Y-m-d'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $lines = [
            'lines' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'array', 'min:1', 'max:500'],
            'lines.*.transport_order_id' => ['nullable', 'uuid', $this->ownRow('tms_transport_orders')],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.billed_amount' => ['required', 'numeric', 'min:0', 'max:9999999999'],
        ];

        return $this->isMethod('POST')
            ? ['carrier_id' => ['required', 'uuid', $this->ownRow('tms_carriers')], ...$rules, ...$lines]
            : [...$rules, ...$lines];
    }
}
