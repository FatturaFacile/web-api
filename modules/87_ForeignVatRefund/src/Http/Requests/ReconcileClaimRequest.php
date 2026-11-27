<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The refund received from the foreign authority: amount, bank account, exchange rate of the receipt and date.
 */
final class ReconcileClaimRequest extends FormRequest
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
            'refunded_amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'bank_account_id' => ['required', 'uuid', Rule::exists('bank_accounts', 'id')->where('tenant_id', $tenantId)],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'received_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
