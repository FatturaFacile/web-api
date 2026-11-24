<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Treasury\Rules\ValidIban;

final class InitiateOpenBankingPaymentRequest extends FormRequest
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
            'consent_id' => ['required', 'uuid', Rule::exists('open_banking_consents', 'id')->where(static fn (Builder $query): Builder => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'creditor_iban' => ['required', 'string', new ValidIban],
            'creditor_name' => ['required', 'string', 'max:140'],
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'currency' => ['required', 'string', 'size:3'],
            'remittance_information' => ['nullable', 'string', 'max:140'],
            'passive_invoice_id' => ['nullable', 'uuid', Rule::exists('passive_invoices', 'id')->where(static fn (Builder $query): Builder => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
        ];
    }
}
