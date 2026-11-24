<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\GeneralLedger\Models\Accrual;

final class PreviewAccrualRequest extends FormRequest
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
            'total_amount' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'competence_start_date' => ['required', 'date'],
            'competence_end_date' => ['required', 'date', 'after_or_equal:competence_start_date'],
            'direction' => ['nullable', 'string', Rule::in(['cost', 'revenue'])],
            'accrual_type' => ['nullable', 'string', Rule::in(Accrual::Types)],
            'document_date' => ['nullable', 'date'],
            'fiscal_year_id' => ['nullable', 'uuid', $this->tenantOwns('fiscal_years')],
            'year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'cutoff_date' => ['nullable', 'date'],
        ];
    }
}
