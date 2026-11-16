<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Requests;

/**
 * Payload to assign a case to a collector.
 */
final class AssignCaseRequest extends CreditFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'collector_id' => ['present', 'nullable', 'uuid', $this->tenantUser()],
        ];
    }
}
