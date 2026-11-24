<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\AnalyticalAccounting\Domain\Enums\DriverType;

/**
 * Validation of a step-down schedule with its ordered steps. Fixed percentage steps must add up to 100.0000 and a
 * center can send in one step only, so the cascade can never flow back.
 */
final class ScheduleRequest extends AnalyticalFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'name' => ['required', 'string', 'max:160', Rule::unique('apportionment_schedules', 'name')->where('tenant_id', $this->tenantId())->whereNull('deleted_at')->ignore($id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.step_order' => ['required', 'integer', 'min:1', 'distinct'],
            'steps.*.source_center_id' => ['required', 'uuid', 'distinct', $this->owned('analytical_centers')],
            'steps.*.driver_type' => ['required', Rule::in(DriverType::values())],
            'steps.*.allocation_driver_id' => ['nullable', 'uuid', $this->owned('analytical_allocation_drivers')],
            'steps.*.include_descendants' => ['sometimes', 'boolean'],
            'steps.*.fixed_weights_json' => ['nullable', 'array'],
            'steps.*.fixed_weights_json.*' => ['numeric', 'gt:0', 'max:100'],
            'steps.*.target_filter_json' => ['nullable', 'array'],
        ];
    }

    /**
     * @param  Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach ((array) $this->input('steps', []) as $i => $step) {
                if (($step['driver_type'] ?? null) !== 'FIXED_PERCENTAGE') {
                    continue;
                }
                $weights = (array) ($step['fixed_weights_json'] ?? []);
                $sum = '0';
                foreach ($weights as $center => $pct) {
                    $sum = bcadd($sum, (string) $pct, 4);
                    if ($center === ($step['source_center_id'] ?? null)) {
                        $validator->errors()->add("steps.{$i}.fixed_weights_json", 'A step cannot allocate to its own sender.');
                    }
                }
                if (bccomp($sum, '100', 4) !== 0) {
                    $validator->errors()->add("steps.{$i}.fixed_weights_json", 'The fixed percentages must add up to 100.0000.');
                }
            }
        });
    }
}
