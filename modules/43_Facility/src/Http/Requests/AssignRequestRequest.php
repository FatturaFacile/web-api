<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Facility\Http\Requests\Concerns\FacilityRules;

/**
 * Assigns a service request to a member of the company.
 */
final class AssignRequestRequest extends FormRequest
{
    use FacilityRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'assignee_id' => ['required', 'uuid', $this->tenantMember()],
        ];
    }
}
