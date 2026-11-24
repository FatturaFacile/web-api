<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

final class ProcessPurchaseReturnRequest extends FormRequest
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
            'purchase_receipt_id' => ['nullable', 'uuid', $this->tenantOwns('purchase_receipts')],
            'supplier_id' => ['nullable', 'uuid'],
            'return_date' => ['required', 'date_format:Y-m-d'],
            'return_type' => ['nullable', 'string', 'in:credit,replacement'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => ['required_without:lines.*.purchase_receipt_line_id', 'nullable', 'uuid'],
            'lines.*.purchase_receipt_line_id' => ['nullable', 'uuid'],
            'lines.*.quantity_returned' => ['required', 'decimal:0,4', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'decimal:0,4', 'min:0'],
            'lines.*.reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
