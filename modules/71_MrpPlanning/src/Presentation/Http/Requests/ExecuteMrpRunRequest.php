<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\MrpPlanning\Domain\Enums\CapacitySchedulingMode;
use Modules\MrpPlanning\Domain\Enums\MrpRunMode;
use Modules\MrpPlanning\Domain\Enums\SchedulingDirection;

/**
 * Parameters of a new MRP run.
 */
final class ExecuteMrpRunRequest extends FormRequest
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
            'run_mode' => ['sometimes', Rule::enum(MrpRunMode::class)],
            'planning_horizon_days' => ['required', 'integer', 'min:1', 'max:730'],
            'capacity_scheduling_mode' => ['sometimes', Rule::enum(CapacitySchedulingMode::class)],
            'scheduling_direction' => ['sometimes', Rule::enum(SchedulingDirection::class)],
            'include_cross_border_customs_lead_time' => ['sometimes', 'boolean'],
            'apply_supplier_shutdown_calendars' => ['sometimes', 'boolean'],
        ];
    }
}
