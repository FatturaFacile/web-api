<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

final class IntercompanyTransferRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_bank_account_id' => ['required', 'uuid', $this->tenantOwns('bank_accounts')],
            'destination_bank_account_id' => ['required', 'uuid', 'different:source_bank_account_id', $this->tenantOwns('bank_accounts')],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'transfer_date' => ['nullable', 'date'],
            'intercompany_interest_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'cash_pool_id' => ['nullable', 'uuid', $this->tenantOwns('cash_pools')],
        ];
    }
}
