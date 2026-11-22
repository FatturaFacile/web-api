<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of the operator board query: the work centre of the company and, optionally, one operator.
 */
final class OperatorBoardRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'work_center_id' => ['required', 'uuid', $this->tenantOwns('mfg_work_centers')],
            'operator_id' => ['nullable', 'uuid', $this->tenantMember()],
        ];
    }
}
