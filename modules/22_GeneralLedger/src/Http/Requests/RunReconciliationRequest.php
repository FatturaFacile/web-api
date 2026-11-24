<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

final class RunReconciliationRequest extends FormRequest
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
            'reconciliation_type' => ['required', 'string', 'in:subledger_ar,subledger_ap,gr_ir,inventory,project'],
            'fiscal_period_id' => ['required', 'uuid', $this->tenantExists('fiscal_periods')],
        ];
    }
}
