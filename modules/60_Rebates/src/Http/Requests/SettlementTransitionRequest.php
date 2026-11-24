<?php

declare(strict_types=1);

namespace Modules\Rebates\Http\Requests;

/**
 * A step of the settlement workflow. The version is the optimistic concurrency token of the proposal; the accounts are
 * only used when the execution sets the rebate off against a supplier payable and posts it to the ledger.
 */
final class SettlementTransitionRequest extends RebatesFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'version' => ['nullable', 'integer', 'min:1'],
            'executed_on' => ['nullable', 'date_format:Y-m-d'],
            'payables_account_id' => ['nullable', 'uuid'],
            'vat_account_id' => ['nullable', 'uuid'],
        ];
    }
}
