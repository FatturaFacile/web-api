<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreIntragroupTransactionRequest extends FormRequest
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
            'relation_id' => ['nullable', 'uuid'],
            'seller_tenant_id' => ['required', 'uuid'],
            'buyer_tenant_id' => ['required', 'uuid', 'different:seller_tenant_id'],
            'asset_type' => ['required', Rule::in(['inventory', 'fixed_asset'])],
            'description' => ['nullable', 'string', 'max:255'],
            'transaction_date' => ['required', 'date'],
            'sale_price' => ['nullable', 'numeric', 'decimal:0,4'],
            'seller_cost' => ['nullable', 'numeric', 'decimal:0,4'],
            'margin_amount' => ['nullable', 'numeric', 'decimal:0,4'],
            'remaining_percentage' => ['nullable', 'numeric', 'between:0,100', 'decimal:0,4'],
            'opening_remaining_percentage' => ['nullable', 'numeric', 'between:0,100', 'decimal:0,4'],
            'useful_life_years' => ['nullable', 'integer', 'between:1,100'],
            'asset_account_id' => ['required', 'uuid'],
            'profit_account_id' => ['required', 'uuid'],
            'depreciation_account_id' => ['nullable', 'uuid'],
        ];
    }
}
