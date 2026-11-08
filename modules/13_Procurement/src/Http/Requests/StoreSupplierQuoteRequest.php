<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The quote of an invited supplier on a request for quotation.
 */
final class StoreSupplierQuoteRequest extends FormRequest
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
        return [
            'supplier_id' => ['required', 'uuid', $this->tenantOwns('suppliers')],
            'quote_reference' => ['nullable', 'string', 'max:100'],
            'quote_date' => ['required', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:quote_date'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.rfq_line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.unit_price' => ['required', 'decimal:0,4', 'min:0'],
            'lines.*.quantity' => ['nullable', 'decimal:0,4', 'gt:0'],
            'lines.*.tax_rate' => ['nullable', 'decimal:0,2', 'min:0', 'max:100'],
            'lines.*.lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ];
    }
}
