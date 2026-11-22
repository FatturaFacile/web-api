<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Manufacturing\Enums\OeeLossCategory;

/**
 * Validation of a machine telemetry ping, optionally with the counters of a period.
 */
final class TelemetryPingRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'telemetry_endpoint_key' => ['required', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'in:online,idle,fault'],
            'recorded_at' => ['nullable', 'date'],
            'period_start' => ['required_with:period_end', 'date'],
            'period_end' => ['required_with:period_start', 'date', 'after:period_start'],
            'unplanned_downtime_minutes' => ['nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:9999999'],
            'loss_category' => ['nullable', 'string', Rule::in(OeeLossCategory::values())],
            'good_units' => ['nullable', ...self::Quantity],
            'scrap_units' => ['nullable', ...self::Quantity],
            'rework_units' => ['nullable', ...self::Quantity],
        ];
    }
}
