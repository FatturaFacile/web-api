<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The SLA behaviour of a policy beyond its targets: the escalation chain, the next-update interval and whether an
 * on-hold ticket stops the clock.
 */
final class UpdateSlaPolicySettingsRequest extends FormRequest
{
    use TenantScopedRules;

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
            'escalation_chain_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('esm_escalation_chains')],
            'next_update_interval_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:525600'],
            'pause_on_on_hold' => ['sometimes', 'boolean'],
        ];
    }
}
