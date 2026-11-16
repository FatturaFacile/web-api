<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The controls that mitigate a risk: the list replaces the current links.
 */
final class RiskControlsRequest extends FormRequest
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
            'control_ids' => ['present', 'array', 'max:100'],
            'control_ids.*' => ['uuid', 'distinct', $this->tenantOwns('gov_controls')],
        ];
    }
}
