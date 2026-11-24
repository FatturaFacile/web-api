<?php

declare(strict_types=1);

namespace Modules\Wms\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Modules\Wms\Models\WmsSlottingRule;

/**
 * Payload to create or change a slotting rule.
 */
final class SlottingRuleRequest extends WmsFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'name' => ['required', 'string', 'max:120'],
            'abc_class' => ['nullable', Rule::in(WmsSlottingRule::AbcClasses)],
            'min_velocity' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'max_velocity' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'target_bin_type' => ['required', Rule::in(WmsSlottingRule::TargetBinTypes)],
            'target_zone_id' => ['nullable', 'uuid', $this->ownRow('warehouse_zones')],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        return $this->isMethod('POST') ? [
            'warehouse_id' => ['required', 'uuid', $this->ownRow('warehouses')],
            ...$rules,
        ] : $rules;
    }

    /**
     * Cross-field rules.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $min = $this->valueOf('min_velocity', 'wms_slotting_rules');
            $max = $this->valueOf('max_velocity', 'wms_slotting_rules');

            if ($min !== null && $max !== null && bccomp((string) $min, (string) $max, 4) > 0) {
                $validator->errors()->add('min_velocity', __('wms_errors.slotting_rule.bounds'));
            }
        });
    }
}
