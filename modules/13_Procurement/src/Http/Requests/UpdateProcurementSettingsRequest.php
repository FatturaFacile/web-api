<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Tolerances of the company for receipts and invoice matching.
 */
final class UpdateProcurementSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'over_receipt_tolerance_percent' => ['sometimes', 'decimal:0,2', 'min:0', 'max:100'],
            'under_receipt_tolerance_percent' => ['sometimes', 'decimal:0,2', 'min:0', 'max:100'],
            'price_tolerance_percent' => ['sometimes', 'decimal:0,2', 'min:0', 'max:100'],
            'price_tolerance_amount' => ['sometimes', 'decimal:0,2', 'min:0'],
            'block_invoice_on_mismatch' => ['sometimes', 'boolean'],
            'landed_cost_clearing_account_code' => ['sometimes', 'string', 'max:20'],
        ];
    }
}
