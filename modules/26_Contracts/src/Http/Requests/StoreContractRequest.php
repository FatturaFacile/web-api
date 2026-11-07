<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Contracts\Enums\ContractKind;

final class StoreContractRequest extends FormRequest
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
        $isSupplier = $this->input('kind') === ContractKind::Supplier->value;
        $counterparties = $isSupplier ? 'suppliers' : 'contacts';
        $numbers = $isSupplier ? 'supplier_contracts' : 'esm_service_contracts';

        return [
            'kind' => ['required', Rule::enum(ContractKind::class)],
            'counterparty_id' => ['required', 'uuid', Rule::exists($counterparties, 'id')->where(static fn (Builder $query): Builder => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'contract_number' => ['required', 'string', 'max:60', Rule::unique($numbers, 'contract_number')->where(static fn (Builder $query): Builder => $query->where('tenant_id', $tenantId))],
            'title' => ['required', 'string', 'max:180'],
            'start_date' => ['required', 'date'],
            'end_date' => [$isSupplier ? 'required' : 'nullable', 'date', 'after_or_equal:start_date'],
            'contract_value' => ['nullable', 'decimal:0,2', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'status' => ['nullable', 'string', 'in:draft,active'],
            'auto_renew' => ['nullable', 'boolean'],
            'notice_period_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'renewal_term_months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'alert_days_before' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ];
    }
}
