<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ShiftPlanning\Infrastructure\Models\ScheduledShiftAssignment;

/**
 * @mixin ScheduledShiftAssignment
 */
final class ShiftAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'roster_schedule_period_id' => $this->roster_schedule_period_id,
            'employee_id' => $this->employee_id,
            'is_open' => $this->isOpen(),
            'shift_date' => $this->shift_date->format('Y-m-d'),
            'shift_template_id' => $this->shift_template_id,
            'template' => $this->whenLoaded('template', fn (): array => [
                'code' => $this->template->code,
                'name' => $this->template->name,
                'color_hex' => $this->template->color_hex,
                'is_on_call' => $this->template->is_on_call,
            ]),
            'start_at' => $this->start_at->toIso8601String(),
            'end_at' => $this->end_at->toIso8601String(),
            'required_role_or_skill_id' => $this->required_role_or_skill_id,
            'estimated_labor_cost' => $this->estimated_labor_cost,
            'surcharge_breakdown' => $this->surcharge_breakdown,
            'compliance_warnings' => $this->compliance_warnings ?? [],
            'has_blocking_issue' => $this->hasBlockingIssue(),
        ];
    }
}
