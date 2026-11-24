<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A return to the supplier: the supplier, the lines and the transport details.
 */
final class SupplierReturnRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware and the policy.
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
        $rules = [
            'supplier_id' => ['required', 'uuid'],
            'warehouse_id' => ['nullable', 'uuid'],
            'rma_id' => ['nullable', 'uuid'],
            'reason_code' => ['nullable', 'string', 'max:40'],
            'carrier' => ['nullable', 'string', 'max:100'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'currency' => ['nullable', 'string', 'size:3'],
            'credit_expected' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'uuid'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'lines.*.goods_receipt_item_id' => ['nullable', 'uuid'],
            'lines.*.rma_line_id' => ['nullable', 'uuid'],
            'lines.*.serial_number' => ['nullable', 'string', 'max:100'],
            'lines.*.lot_number' => ['nullable', 'string', 'max:100'],
        ];

        return $this->isMethod('PUT') ? array_map(static fn (array $rule): array => ['sometimes', ...$rule], $rules) : $rules;
    }
}
