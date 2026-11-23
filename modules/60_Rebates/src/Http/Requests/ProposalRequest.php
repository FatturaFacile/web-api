<?php

declare(strict_types=1);

namespace Modules\Rebates\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Rebates\Models\RebateSettlement;

/**
 * A settlement proposal of the open accruals of a supplier rebate agreement.
 */
final class ProposalRequest extends RebatesFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'execution_mode' => ['required', Rule::in(RebateSettlement::ExecutionModes)],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'passive_invoice_id' => ['nullable', 'uuid', Rule::requiredIf(fn (): bool => $this->input('execution_mode') === RebateSettlement::ApSetOff), $this->ownRow('passive_invoices')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
