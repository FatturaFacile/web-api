<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreChartOfAccountRequest extends FormRequest
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
            'account_code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'account_class' => ['nullable', 'string', 'max:30'],
            'account_type' => ['required', 'string', 'in:asset,liability,equity,revenue,expense,cogs,wip,memo,statistical'],
            'normal_balance' => ['required', 'string', 'in:debit,credit'],
            'parent_account_id' => ['nullable', 'uuid', 'exists:chart_of_accounts,id'],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_reconciliation_account' => ['nullable', 'boolean'],
            'reconciliation_type' => ['nullable', 'string', 'max:20'],
            'allows_manual_posting' => ['nullable', 'boolean'],
            'is_cost_center_mandatory' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
