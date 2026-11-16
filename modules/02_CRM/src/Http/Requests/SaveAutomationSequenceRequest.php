<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\CRM\Models\AutomationSequence;
use Modules\CRM\Models\AutomationStep;

/**
 * A marketing automation sequence with its ordered steps (the steps sent replace the stored ones).
 */
final class SaveAutomationSequenceRequest extends CrmExtensionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->forMethod([
                'name' => ['required', 'string', 'max:150'],
                'entity' => ['required', Rule::in(['contacts', 'leads'])],
                'trigger_type' => ['required', Rule::in(AutomationSequence::Triggers)],
                'segment_id' => ['nullable', 'uuid', $this->ownRow('crm_segments')],
                'exit_conditions' => ['nullable', 'array'],
                'exit_conditions.*' => ['string', Rule::in(AutomationSequence::ExitConditions)],
                'is_active' => ['sometimes', 'boolean'],
            ]),
            'steps' => ['sometimes', 'array', 'max:50'],
            'steps.*.step_type' => ['required', Rule::in(AutomationStep::Types)],
            'steps.*.delay_days' => ['required', 'integer', 'min:0', 'max:365'],
            'steps.*.subject' => ['nullable', 'required_if:steps.*.step_type,email', 'string', 'max:200'],
            'steps.*.body' => ['nullable', 'required_if:steps.*.step_type,email', 'string', 'max:20000'],
        ];
    }
}
