<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An exception that lets one member hold the conflicting duties of a rule until a date.
 */
final class SodExceptionRequest extends FormRequest
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
        return [
            'rule_id' => ['required', 'uuid', $this->tenantOwns('gov_sod_rules')],
            'user_id' => ['required', 'uuid', $this->tenantMember()],
            'justification' => ['required', 'string', 'min:10', 'max:4000'],
            'expires_at' => ['required', 'date_format:Y-m-d', 'after:today', 'before_or_equal:'.now()->addYear()->toDateString()],
        ];
    }
}
