<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of a phase take-over: the operation, the operator (a member of the company) and the machine.
 */
final class TakeOverPhaseRequest extends MesCommandRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->commandRules(),
            'production_order_operation_id' => ['required', 'uuid', $this->tenantOwns('mfg_production_order_operations')],
            'operator_id' => ['required', 'uuid', $this->tenantMember()],
            'machine_id' => ['nullable', 'uuid', $this->tenantOwns('mfg_machine_centers')],
        ];
    }
}
