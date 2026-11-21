<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\KpiDashboards\Domain\Enums\ComparisonMode;
use Modules\KpiDashboards\Domain\Enums\KpiCode;
use Modules\KpiDashboards\Domain\Enums\RoleCode;
use Modules\KpiDashboards\Domain\Enums\WidgetType;

final class SaveDashboardLayoutRequest extends FormRequest
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
            'dashboard_id' => ['nullable', 'uuid'],
            'role' => ['nullable', Rule::enum(RoleCode::class)],
            'custom_title' => ['nullable', 'string', 'max:160'],
            'refresh_interval_seconds' => ['required', 'integer', 'min:30', 'max:86400'],
            'expected_version' => ['nullable', 'integer', 'min:1'],
            'widgets' => ['required', 'array', 'min:1', 'max:40'],
            'widgets.*.kpi_code' => ['required', Rule::enum(KpiCode::class)],
            'widgets.*.widget_type' => ['nullable', Rule::enum(WidgetType::class)],
            'widgets.*.default_time_comparison' => ['nullable', Rule::enum(ComparisonMode::class)],
            'widgets.*.x' => ['required', 'integer', 'min:0', 'max:11'],
            'widgets.*.y' => ['required', 'integer', 'min:0'],
            'widgets.*.w' => ['required', 'integer', 'min:1', 'max:12'],
            'widgets.*.h' => ['required', 'integer', 'min:1', 'max:12'],
            'widgets.*.threshold_rules' => ['nullable', 'array'],
        ];
    }
}
