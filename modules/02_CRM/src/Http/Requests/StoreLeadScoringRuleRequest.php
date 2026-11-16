<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\CRM\Models\LeadActivityEvent;
use Modules\CRM\Models\LeadScoringRule;

/**
 * Create (POST) and full update (PUT) of a scoring rule. On PUT every field is optional.
 */
final class StoreLeadScoringRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // enforced by LeadScoringRulePolicy in the controller
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isUpdate() ? 'sometimes' : 'required';
        $tenantId = $this->tenantId();
        $ruleId = $this->route('id');

        return [
            'code' => [
                $required, 'string', 'max:50', 'regex:/^[A-Za-z0-9_.-]+$/',
                Rule::unique('crm_lead_scoring_rules', 'code')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')
                    ->ignore(is_string($ruleId) ? $ruleId : null),
            ],
            'name' => [$required, 'string', 'max:150'],
            'category' => [$required, 'string', Rule::in(LeadScoringRule::CATEGORIES)],
            'field' => ['nullable', 'string', 'max:100'],
            'event_type' => ['nullable', 'string', Rule::in(LeadActivityEvent::TYPES)],
            'operator' => [$required, 'string', Rule::in(LeadScoringRule::OPERATORS)],
            'value' => ['nullable'],
            'points' => [$required, 'integer', 'between:-100,100'],
            'max_applications' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'decay_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $category = $this->input('category', $this->existing('category'));
                $operator = $this->input('operator', $this->existing('operator'));
                $field = $this->input('field', $this->existing('field'));
                $eventType = $this->input('event_type', $this->existing('event_type'));
                $value = $this->exists('value') ? $this->input('value') : $this->existing('value');

                if ($category === 'demographic') {
                    if (! is_string($field) || ! in_array($field, LeadScoringRule::DEMOGRAPHIC_FIELDS, true)) {
                        $validator->errors()->add('field', 'The field must be one of: '.implode(', ', LeadScoringRule::DEMOGRAPHIC_FIELDS).'.');
                    }
                }

                if ($category === 'behavioral' && empty($eventType)) {
                    $validator->errors()->add('event_type', 'The event type is required for behavioral rules.');
                }

                if (in_array($operator, ['in', 'between'], true) && ! is_array($value)) {
                    $validator->errors()->add('value', 'The value must be an array for the '.$operator.' operator.');
                }

                if (in_array($operator, ['gte', 'lte'], true) && ! is_numeric($value)) {
                    $validator->errors()->add('value', 'The value must be numeric for the '.$operator.' operator.');
                }

                if (in_array($operator, ['eq', 'contains'], true) && ($value === null || $value === '' || $value === [])) {
                    $validator->errors()->add('value', 'The value is required for the '.$operator.' operator.');
                }
            },
        ];
    }

    private function isUpdate(): bool
    {
        return $this->isMethod('PUT') || $this->isMethod('PATCH');
    }

    private function tenantId(): ?string
    {
        return app(TenantContext::class)->getTenantId();
    }

    private function existing(string $attribute): mixed
    {
        if (! $this->isUpdate()) {
            return null;
        }

        $id = $this->route('id');
        $rule = is_string($id) ? LeadScoringRule::query()->where('tenant_id', $this->tenantId())->find($id) : null;

        return $rule?->getAttribute($attribute);
    }
}
