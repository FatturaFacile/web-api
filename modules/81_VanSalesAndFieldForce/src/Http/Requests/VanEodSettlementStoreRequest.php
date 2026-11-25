<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class VanEodSettlementStoreRequest extends FormRequest
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
            'van_warehouse_id' => ['required', 'uuid'],
            'van_load_manifest_id' => ['nullable', 'uuid'],
            'field_route_plan_id' => ['nullable', 'uuid'],
            'settlement_date' => ['nullable', 'date'],
            'stock_reconciliation_lines' => ['nullable', 'array'],
            'empties_reconciliation' => ['nullable', 'array'],
            'opening_cash_float' => ['nullable', 'numeric', 'min:0'],
            'collected_cash_amount' => ['nullable', 'numeric', 'min:0'],
            'collected_pos_card_amount' => ['nullable', 'numeric', 'min:0'],
            'collected_twint_amount' => ['nullable', 'numeric', 'min:0'],
            'collected_cheque_amount' => ['nullable', 'numeric', 'min:0'],
            'collected_bank_transfer_amount' => ['nullable', 'numeric', 'min:0'],
            'counted_cash_remittance' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
