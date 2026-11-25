<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SalesBoundedContext\Domain\Enums\BStockConditionGrade;

final class ProcessReverseLogisticsReturnRequest extends FormRequest
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
            'dossier_id' => ['nullable', 'uuid'],
            'original_invoice_id' => ['nullable', 'uuid'],
            'rma_number' => ['nullable', 'string', 'max:40'],
            'return_reason' => ['required', 'string', 'max:120'],
            'is_advance_cross_shipping' => ['nullable', 'boolean'],
            'replacement_shipment_ref' => ['nullable', 'string', 'max:64'],
            'b_stock_grade' => ['required', Rule::in(BStockConditionGrade::values())],
            'inventory_disposition' => ['nullable', 'string', 'in:restock_a,b_stock_outlet,supplier_claim,scrap'],
            'original_invoice_date' => ['nullable', 'date'],
            'return_net_amount' => ['required', 'numeric', 'min:0'],
            'vat_rate_pct' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'currency' => ['nullable', 'string', 'size:3'],
        ];
    }
}
