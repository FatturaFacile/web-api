<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\CRM\Models\LeadScoringRule;

/**
 * Creation or update of a lead scoring rule. Field rules need a whitelisted field and operator; activity rules need
 * an activity type or status to count. On update every field is optional.
 */
final class SaveLeadScoringRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';
        $type = $this->input('rule_type', $this->route('id') !== null ? LeadScoringRule::find($this->route('id'))?->rule_type : null);

        return [
            'name' => [$required, 'string', 'max:150'],
            'rule_type' => [$required, Rule::in([LeadScoringRule::TypeField, LeadScoringRule::TypeActivity])],
            'field' => [$type === LeadScoringRule::TypeField && $creating ? 'required' : 'nullable', Rule::in(LeadScoringRule::Fields)],
            'operator' => [$type === LeadScoringRule::TypeField && $creating ? 'required' : 'nullable', Rule::in(LeadScoringRule::Operators)],
            'value' => ['nullable', 'string', 'max:255'],
            'activity_type' => ['nullable', 'string', 'in:task,call,meeting,note,email,follow_up'],
            'activity_status' => ['nullable', 'string', 'in:pending,in_progress,completed,cancelled'],
            'points' => [$required, 'integer', 'between:-100,100'],
            'max_points' => ['nullable', 'integer', 'between:0,100'],
            'sequence' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
