<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ServiceManagement\Models\EscalationStep;

/**
 * An escalation chain (#43) with its steps. On update the steps sent replace the existing ones.
 */
final class SaveEscalationChainRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:60', Rule::unique('esm_escalation_chains', 'code')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at')->ignore($this->route('id'))],
            'name' => [$required, 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
            'steps' => [$required, 'array', 'min:1', 'max:30'],
            'steps.*.target' => ['required', 'string', Rule::in(EscalationStep::Targets)],
            'steps.*.threshold_percent' => ['required', 'integer', 'min:1', 'max:1000'],
            'steps.*.action' => ['required', 'string', Rule::in(EscalationStep::Actions)],
            'steps.*.notify_user_ids' => ['sometimes', 'nullable', 'array', 'max:20'],
            'steps.*.notify_user_ids.*' => ['uuid', $this->tenantMember()],
            'steps.*.notify_team_lead' => ['sometimes', 'boolean'],
            'steps.*.team_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('esm_service_teams')],
            'steps.*.priority' => ['sometimes', 'nullable', 'string', Rule::in(['low', 'medium', 'high', 'critical'])],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function ($validator): void {
            foreach ((array) $this->input('steps', []) as $index => $step) {
                $action = $step['action'] ?? null;

                if ($action === 'notify' && empty($step['notify_user_ids']) && empty($step['notify_team_lead'])) {
                    $validator->errors()->add("steps.{$index}.notify_user_ids", 'A notify step needs recipients or the team lead.');
                }

                if ($action === 'reassign_team' && empty($step['team_id'])) {
                    $validator->errors()->add("steps.{$index}.team_id", 'A reassign step needs a team.');
                }

                if ($action === 'raise_priority' && empty($step['priority'])) {
                    $validator->errors()->add("steps.{$index}.priority", 'A raise priority step needs the new priority.');
                }
            }
        }];
    }
}
