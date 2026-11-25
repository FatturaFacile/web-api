<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyChannel;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyTriggerEvent;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyType;
use Modules\CustomerSatisfaction\Http\Requests\Concerns\ScopesToTenant;

final class SaveTemplateRequest extends FormRequest
{
    use ScopesToTenant;

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
            'code' => [$required, 'string', 'max:60', 'regex:/^[A-Z0-9_-]+$/', $this->tenantUnique('survey_templates', 'code', $this->route('id'))],
            'name' => [$required, 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'survey_type' => [$required, Rule::enum(SurveyType::class)],
            'trigger_event' => [$required, Rule::enum(SurveyTriggerEvent::class)],
            'trigger_filters' => ['nullable', 'array'],
            'trigger_filters.department_code' => ['nullable', 'array'],
            'trigger_filters.department_code.*' => ['string', 'max:60'],
            'trigger_filters.branch_code' => ['nullable', 'array'],
            'trigger_filters.branch_code.*' => ['string', 'max:60'],
            'trigger_filters.product_line_code' => ['nullable', 'array'],
            'trigger_filters.product_line_code.*' => ['string', 'max:60'],
            'trigger_filters.customer_tier' => ['nullable', 'array'],
            'trigger_filters.customer_tier.*' => ['string', 'max:30'],
            'trigger_filters.min_amount' => ['nullable', 'numeric', 'min:0'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'channels' => [$required, 'array', 'min:1'],
            'channels.*' => ['distinct', Rule::enum(SurveyChannel::class)],
            'delay_hours' => ['sometimes', 'integer', 'min:0', 'max:720'],
            'cooldown_days' => ['sometimes', 'integer', 'min:0', 'max:730'],
            'detractor_threshold' => ['nullable', 'integer', 'min:0', 'max:10'],
            'sla_hours' => ['sometimes', 'integer', 'min:1', 'max:720'],
            'allow_voice_comment' => ['sometimes', 'boolean'],
            'messages' => ['nullable', 'array'],
            'messages.*' => ['array'],
            'messages.*.subject' => ['required', 'string', 'max:200'],
            'messages.*.body' => ['required', 'string', 'max:4000'],
            'messages.*.one_click_label' => ['nullable', 'string', 'max:200'],
            'follow_up_config' => ['nullable', 'array'],
            'follow_up_config.low' => ['nullable', 'array'],
            'follow_up_config.mid' => ['nullable', 'array'],
            'follow_up_config.high' => ['nullable', 'array'],
            'follow_up_config.*.*' => ['string', 'max:500'],
            'dimensions' => ['nullable', 'array'],
            'dimensions.department_code' => ['nullable', 'string', 'max:60'],
            'dimensions.product_line_code' => ['nullable', 'string', 'max:60'],
            'dimensions.branch_code' => ['nullable', 'string', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
