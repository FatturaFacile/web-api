<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreConsolidationGroupRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:190', Rule::unique('gl_consolidation_groups', 'name')->where(static fn (Builder $query): Builder => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'))],
            'group_currency' => ['required', 'string', 'size:3', 'alpha'],
            'translation_reserve_code' => ['nullable', 'string', 'max:40'],
            'consolidation_difference_code' => ['nullable', 'string', 'max:40'],
            'equity_method_result_code' => ['nullable', 'string', 'max:40'],
            'equity_method_reserve_code' => ['nullable', 'string', 'max:40'],
            'elimination_difference_policy' => ['nullable', Rule::in(['keep_difference', 'post_to_difference_account'])],
            'goodwill_method' => ['nullable', Rule::in(['none', 'straight_line', 'impairment_only'])],
            'goodwill_amortisation_years' => ['nullable', 'integer', 'between:1,100'],
            'require_account_mapping' => ['nullable', 'boolean'],
            'synthetic_accounts' => ['nullable', 'array'],
            'deferred_tax_on_unrealised_profit' => ['nullable', 'boolean'],
            'unrealised_profit_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'fair_value_first_year_rule' => ['nullable', Rule::in(['pro_rata', 'full_year'])],
            'negative_goodwill_treatment' => ['nullable', Rule::in(['consolidation_reserve', 'immediate_income'])],
            'goodwill_translation' => ['nullable', Rule::in(['historical', 'closing'])],
            'synthetic_accounts.*' => ['string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
