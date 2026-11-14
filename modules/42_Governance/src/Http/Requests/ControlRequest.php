<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Governance\Models\GovControl;

/**
 * A control of the library, on creation (POST) or change (PUT). A change of status follows the state machine.
 */
final class ControlRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * Access is decided by the module.access middleware and the policy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'title' => [$presence, 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:4000'],
            'control_type' => [$presence, Rule::in(GovControl::Types)],
            'frequency' => [$presence, Rule::in(GovControl::Frequencies)],
            'framework_reference' => ['nullable', 'string', 'max:120'],
            'owner_id' => ['nullable', 'uuid', $this->tenantMember()],
            'status' => ['sometimes', Rule::in(GovControl::Statuses)],
        ];
    }
}
