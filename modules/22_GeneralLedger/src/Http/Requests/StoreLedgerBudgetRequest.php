<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreLedgerBudgetRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'fiscal_year_id' => ['required', 'uuid', Rule::exists('fiscal_years', 'id')->where(static fn (Builder $query): Builder => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'name' => ['required', 'string', 'max:190', Rule::unique('gl_budgets', 'name')->where(fn (Builder $query): Builder => $query->where('tenant_id', $tenantId)->where('fiscal_year_id', $this->input('fiscal_year_id')))],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
