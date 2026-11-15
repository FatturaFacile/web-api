<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Modules\FiscalRetail\Models\RetailReturn;

/**
 * A return of goods of a POS sale, settled in an open session, with an optional exchange (new lines and the payments
 * of what the customer still owes after the value of the returned goods).
 */
final class RetailReturnRequest extends TicketItemsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'pos_session_id' => ['required', 'uuid', Rule::exists('pos_sessions', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.pos_sale_item_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'refund_method' => ['required', Rule::in(RetailReturn::RefundMethods)],
            'reason' => ['nullable', 'string', 'max:190'],
            'exchange' => ['nullable', 'array'],
            ...collect($this->itemRules('exchange.items'))->map(static fn (array $rules): array => array_map(static fn (mixed $rule): mixed => $rule === 'required' ? 'required_with:exchange' : $rule, $rules))->all(),
            ...$this->paymentRules('exchange.payments'),
        ];
    }
}
