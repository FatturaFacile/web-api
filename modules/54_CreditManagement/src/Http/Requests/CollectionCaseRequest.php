<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\CreditManagement\Models\CreditCollectionCase;

/**
 * Payload to open a collection case on the overdue invoices of a customer.
 */
final class CollectionCaseRequest extends CreditFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'uuid', $this->ownRow('contacts')],
            'invoice_ids' => ['nullable', 'array', 'max:500'],
            'invoice_ids.*' => ['uuid', 'distinct'],
            'strategy_id' => ['nullable', 'uuid', $this->ownRow('credit_dunning_strategies')],
            'collector_id' => ['nullable', 'uuid', $this->tenantUser()],
            'priority' => ['nullable', Rule::in(CreditCollectionCase::Priorities)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
