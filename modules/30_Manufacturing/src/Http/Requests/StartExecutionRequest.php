<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of a job card started on an operation: the operator is a member of the company, the machine one of its
 * machine centres.
 */
final class StartExecutionRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'production_order_operation_id' => ['required', 'uuid', $this->tenantOwns('mfg_production_order_operations')],
            'operator_id' => ['nullable', 'uuid', $this->tenantMember()],
            'machine_id' => ['nullable', 'uuid', $this->tenantOwns('mfg_machine_centers')],
        ];
    }
}
