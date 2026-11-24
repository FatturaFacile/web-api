<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

final class GenerateAccrualReversalsRequest extends FormRequest
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
            'accrual_ids' => ['nullable', 'array'],
            'accrual_ids.*' => ['uuid', $this->tenantOwns('gl_accruals')],
            'reversal_date' => ['nullable', 'date'],
        ];
    }
}
