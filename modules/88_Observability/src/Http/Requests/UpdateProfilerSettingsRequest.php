<?php

declare(strict_types=1);

namespace Modules\Observability\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateProfilerSettingsRequest extends FormRequest
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
            'slow_query_threshold_ms' => ['sometimes', 'integer', 'min:1', 'max:600000'],
            'n_plus_one_threshold' => ['sometimes', 'integer', 'min:2', 'max:10000'],
            'explain_enabled' => ['sometimes', 'boolean'],
            'alert_endpoint_p95_ms' => ['sometimes', 'integer', 'min:1', 'max:600000'],
            'alert_tenant_share_pct' => ['sometimes', 'numeric', 'min:1', 'max:100'],
            'alert_connector_latency_ms' => ['sometimes', 'integer', 'min:1', 'max:600000'],
            'retention_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ];
    }
}
