<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreIntragroupDividendRequest extends FormRequest
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
            'payer_tenant_id' => ['required', 'uuid'],
            'receiver_tenant_id' => ['required', 'uuid', 'different:payer_tenant_id'],
            'dividend_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'income_account_id' => ['required', 'uuid'],
            'equity_account_id' => ['required', 'uuid'],
        ];
    }
}
