<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\GeneralLedger\Models\Accrual;

class AccrualSummaryRequest extends FormRequest
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
            'fiscal_year_id' => ['nullable', 'uuid', $this->tenantOwns('fiscal_years')],
            'year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'accrual_type' => ['nullable', 'string', Rule::in(Accrual::Types)],
            'account_id' => ['nullable', 'uuid', $this->tenantOwns('chart_of_accounts')],
            'source_type' => ['nullable', 'string', Rule::in(Accrual::SourceTypes)],
            'status' => ['nullable', 'string', Rule::in(['calculated', 'closing_posted', 'reversal_posted'])],
            'competence_from' => ['nullable', 'date'],
            'competence_to' => ['nullable', 'date', 'after_or_equal:competence_from'],
            'search' => ['nullable', 'string', 'max:120'],
        ];
    }
}
