<?php

declare(strict_types=1);

namespace App\Http\Requests\Cpq;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveQuoteApprovalTierRequest extends FormRequest
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
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';
        $ignore = $this->route('id');

        return [
            'level' => [$presence, 'integer', 'min:1', 'max:20', Rule::unique('quote_approval_tiers', 'level')->where('tenant_id', $tenantId)->whereNull('deleted_at')->ignore(is_string($ignore) ? $ignore : null)],
            'name' => [$presence, 'string', 'max:100'],
            'approver_role' => [$presence, 'string', 'max:50'],
            'max_line_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'max_global_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'min_margin_percent' => ['nullable', 'numeric', 'min:-100', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
