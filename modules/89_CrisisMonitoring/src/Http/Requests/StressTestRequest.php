<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Modules\CrisisMonitoring\Domain\Stress\StressShock;

/**
 * The shock of a stress test: revenue drop, collection delay, interest rate hike and an extraordinary outflow.
 */
final class StressTestRequest extends FormRequest
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
            'revenue_drop_percent' => ['nullable', 'numeric', 'between:0,100'],
            'dso_delay_days' => ['nullable', 'integer', 'between:0,180'],
            'interest_rate_bps' => ['nullable', 'integer', 'between:0,2000'],
            'one_off_outflow_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'as_of' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function shock(): StressShock
    {
        return StressShock::fromArray($this->validated());
    }

    public function asOf(): ?CarbonImmutable
    {
        $date = $this->validated('as_of');

        return $date === null ? null : CarbonImmutable::parse((string) $date);
    }
}
