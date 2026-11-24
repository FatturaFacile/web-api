<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\GeneralLedger\Models\Accrual;

final class GenerateAccrualClosingEntriesRequest extends FormRequest
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
        $rules = [
            'fiscal_year_id' => ['nullable', 'uuid', $this->tenantOwns('fiscal_years')],
            'year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'item_keys' => ['nullable', 'array'],
            'item_keys.*.source_type' => ['required_with:item_keys', 'string', Rule::in(Accrual::SourceTypes)],
            'item_keys.*.source_line_id' => ['required_with:item_keys', 'uuid'],
            'balance_sheet_accounts' => ['nullable', 'array:'.implode(',', Accrual::Types)],
            'auto_generate_reversals' => ['nullable', 'boolean'],
        ];

        foreach (Accrual::Types as $type) {
            $rules["balance_sheet_accounts.{$type}"] = ['nullable', 'uuid', $this->tenantOwns('chart_of_accounts')];
        }

        return $rules;
    }
}
