<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\KpiDashboards\Domain\Enums\KpiCode;

final class ConfigureThresholdAlertRequest extends FormRequest
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
        return [
            'rule_id' => ['nullable', 'uuid'],
            'kpi_code' => ['required', Rule::enum(KpiCode::class)],
            'operator' => ['required', Rule::in(['<', '<=', '>', '>='])],
            'warning_value' => ['nullable', 'numeric', 'required_without:critical_value'],
            'critical_value' => ['nullable', 'numeric', 'required_without:warning_value'],
            'channels' => ['nullable', 'array', 'min:1'],
            'channels.*' => [Rule::in(['in_app', 'push', 'email'])],
            'cooldown_minutes' => ['nullable', 'integer', 'min:1', 'max:43200'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
