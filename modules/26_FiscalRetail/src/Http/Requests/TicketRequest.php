<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;

/**
 * Payload of a sales ticket: the lines, the payments split by method (the cash payment may exceed what is due, the
 * excess is the change) and the optional customer.
 */
final class TicketRequest extends TicketItemsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'pos_session_id' => ['required', 'uuid', Rule::exists('pos_sessions', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'contact_id' => ['nullable', 'uuid', Rule::exists('contacts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            ...$this->itemRules('items'),
            ...$this->paymentRules('payments'),
        ];
    }
}
