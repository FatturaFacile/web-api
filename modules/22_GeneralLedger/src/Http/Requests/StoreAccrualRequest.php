<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\GeneralLedger\Models\Accrual;

/**
 * An accrual or deferral (rateo o risconto): type, amount of the whole coverage, coverage dates, cut-off and accounts.
 */
final class StoreAccrualRequest extends FormRequest
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
            'accrual_type' => ['required', Rule::in(Accrual::Types)],
            'description' => ['required', 'string', 'max:255'],
            'total_amount' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'coverage_start' => ['required', 'date'],
            'coverage_end' => ['required', 'date', 'after:coverage_start'],
            'cutoff_date' => ['required', 'date'],
            'income_statement_account_id' => ['required', 'uuid', $this->tenantOwns('chart_of_accounts')],
            'balance_sheet_account_id' => ['required', 'uuid', $this->tenantOwns('chart_of_accounts')],
            'cost_center_id' => ['nullable', 'uuid', $this->tenantOwns('cost_centers')],
        ];
    }
}
