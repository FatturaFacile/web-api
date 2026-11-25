<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyTriggerEvent;
use Modules\CustomerSatisfaction\Domain\Support\SurveyLocale;
use Modules\CustomerSatisfaction\Http\Requests\Concerns\ScopesToTenant;

/**
 * An ERP event reported to the survey engine by another module or integration (order delivered, onboarding completed, ...).
 */
final class TriggerSurveyRequest extends FormRequest
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
        return [
            'event' => ['required', Rule::enum(SurveyTriggerEvent::class)],
            'customer_id' => ['required', 'uuid', $this->tenantExists('contacts')],
            'source_entity_type' => ['nullable', 'string', 'max:60'],
            'source_entity_id' => ['nullable', 'uuid'],
            'locale' => ['nullable', Rule::in(SurveyLocale::Supported)],
            'dimensions' => ['nullable', 'array'],
            'dimensions.department_code' => ['nullable', 'string', 'max:60'],
            'dimensions.technician_id' => ['nullable', 'uuid'],
            'dimensions.product_line_code' => ['nullable', 'string', 'max:60'],
            'dimensions.branch_code' => ['nullable', 'string', 'max:60'],
            'dimensions.customer_tier' => ['nullable', 'string', 'max:30'],
            'dimensions.amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
