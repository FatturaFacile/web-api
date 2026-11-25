<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveContractRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'code' => [$required, 'string', 'max:40', Rule::unique('gdt_contracts', 'code')->where('tenant_id', $tenantId)->whereNull('deleted_at')->ignore($this->route('id'))],
            'name' => [$required, 'string', 'max:160'],
            'contact_id' => [$required, 'uuid'],
            'include_child_contacts' => ['sometimes', 'boolean'],
            'jurisdiction' => ['nullable', Rule::in(['IT', 'CH'])],
            'currency' => ['nullable', 'string', 'size:3'],
            'starts_on' => [$required, 'date'],
            'ends_on' => [$required, 'date', 'after_or_equal:starts_on'],
            'discount_1_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_2_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_3_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cedi_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'pallet_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'tolerance_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'expense_account_id' => ['nullable', 'uuid'],
            'provision_account_id' => ['nullable', 'uuid'],
            'payable_account_id' => ['nullable', 'uuid'],
            'vat_account_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
