<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoyaltyTransactionRequest extends FormRequest
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
            'type' => ['required', Rule::in(['earn', 'redeem', 'topup', 'adjust'])],
            'amount' => ['required', 'numeric', $this->input('type') === 'adjust' ? 'not_in:0' : 'gt:0'],
            'description' => ['nullable', 'string', 'max:255'],
            'invoice_id' => ['nullable', 'uuid', Rule::exists('invoices', 'id')->where('tenant_id', $tenantId)],
        ];
    }
}
