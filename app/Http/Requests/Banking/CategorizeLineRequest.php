<?php

declare(strict_types=1);

namespace App\Http\Requests\Banking;

use App\Models\BankStatementLine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Manual categorisation of a line that is not linked to an invoice. The ledger account defaults to the tenant
 * preference of the category (taxes, payroll, bank expenses) when omitted.
 */
class CategorizeLineRequest extends FormRequest
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
            'category' => ['required', 'string', Rule::in(BankStatementLine::NON_INVOICE_CATEGORIES)],
            'ledger_account_code' => ['nullable', 'string', 'regex:/^[A-Za-z0-9.\-]{1,20}$/'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
