<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Treasury\Rules\ValidIban;

/**
 * A bank account of the active tenant. The IBAN is checked (ISO 13616) and unique in the tenant; the ledger account,
 * when given, must be an account of the tenant chart. On update (PUT/PATCH) every field is optional and the IBAN and
 * currency cannot change, because movements were already booked on them.
 */
final class StoreBankAccountRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Upper-case IBAN without spaces, upper-case currency.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'iban' => $this->has('iban') ? strtoupper(str_replace(' ', '', (string) $this->input('iban'))) : null,
            'currency' => $this->has('currency') ? strtoupper((string) $this->input('currency')) : null,
        ], static fn (?string $value): bool => $value !== null));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'bank_name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:150'],
            'branch_name' => ['nullable', 'string', 'max:150'],
            'iban' => $isUpdate ? ['prohibited'] : ['required', 'string', 'max:34', new ValidIban, Rule::unique('bank_accounts', 'iban')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'bic_swift' => ['nullable', 'string', 'regex:/^[A-Za-z]{6}[A-Za-z0-9]{2}([A-Za-z0-9]{3})?$/'],
            'account_number' => ['nullable', 'string', 'max:50'],
            'currency' => $isUpdate ? ['prohibited'] : ['required', 'string', 'size:3', 'alpha'],
            'account_type' => ['nullable', 'string', 'in:checking,savings,escrow,credit_line,cash_pool_header'],
            'gl_account_id' => ['nullable', 'uuid', $this->tenantOwns('chart_of_accounts')],
            'overdraft_limit' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'credit_line_limit' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'opening_balance' => $isUpdate ? ['prohibited'] : ['nullable', 'numeric', 'decimal:0,2'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
