<?php

declare(strict_types=1);

namespace Modules\Sustainability\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sustainability\Models\EsgMaterialityTopic;

/**
 * A double materiality assessment with its scored topics (impact and financial scores from 0 to 5).
 */
final class MaterialityAssessmentRequest extends FormRequest
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
        $rules = [
            'name' => ['required', 'string', 'max:190'],
            'reporting_period_id' => ['nullable', 'uuid', $this->tenantOwns('esg_reporting_periods')],
            'threshold' => ['nullable', 'numeric', 'between:0.5,5'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];

        if ($this->isMethod('PUT')) {
            $rules = array_map(static fn (array $rule): array => ['sometimes', ...$rule], $rules);
        }

        return $rules + [
            'topics' => ['sometimes', 'array', 'max:60'],
            'topics.*.esrs_code' => ['required', Rule::in(EsgMaterialityTopic::EsrsCodes)],
            'topics.*.name' => ['required', 'string', 'max:190', 'distinct'],
            'topics.*.impact_score' => ['required', 'numeric', 'between:0,5'],
            'topics.*.financial_score' => ['required', 'numeric', 'between:0,5'],
            'topics.*.rationale' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
