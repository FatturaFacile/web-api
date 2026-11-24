<?php

declare(strict_types=1);

namespace App\Http\Requests\Banking;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Models\BankStatementLine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Manual categorisation of a line that is not linked to an invoice. The ledger account must be in the chart of accounts
 * of the tenant; when omitted, the account configured for the category in `banking.ledger_defaults` is used, and
 * without one the categorisation is refused.
 */
class CategorizeLineRequest extends FormRequest
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
            'category' => ['required', 'string', Rule::in(BankStatementLine::NON_INVOICE_CATEGORIES)],
            'ledger_account_code' => ['nullable', 'string', 'regex:/^[A-Za-z0-9.\-]{1,20}$/', $this->tenantOwns('chart_of_accounts', 'account_code')],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
