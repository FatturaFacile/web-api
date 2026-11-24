<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A remediation action of a finding, on creation (POST under the finding) or change (PUT).
 */
final class ActionRequest extends FormRequest
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
            'owner_id' => [$presence, 'uuid', $this->tenantMember()],
            'due_date' => [$presence, 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }
}
