<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The quote of a supplier to a request for quotation, sent from the portal.
 */
final class PortalQuoteRequest extends FormRequest
{
    /**
     * The portal token authenticates the supplier.
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
        return [
            'quote_reference' => ['nullable', 'string', 'max:100'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:730'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.rfq_line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'lines.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:999999999'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.lead_time_days' => ['nullable', 'integer', 'min:0', 'max:730'],
        ];
    }
}
