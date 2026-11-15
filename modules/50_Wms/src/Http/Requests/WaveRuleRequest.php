<?php

declare(strict_types=1);

namespace Modules\Wms\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * Payload to create or change a wave rule.
 */
final class WaveRuleRequest extends WmsFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'name' => ['required', 'string', 'max:120'],
            'min_priority' => ['nullable', 'integer', 'min:0', 'max:100'],
            'due_within_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
            'reference_prefix' => ['nullable', 'string', 'max:60'],
            'min_tasks' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'max_tasks' => ['sometimes', 'integer', 'min:1', 'max:1000'],
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

            $min = $this->valueOf('min_tasks', 'wms_wave_rules');
            $max = $this->valueOf('max_tasks', 'wms_wave_rules');

            if ($min !== null && $max !== null && (int) $min > (int) $max) {
                $validator->errors()->add('min_tasks', __('wms_errors.wave_rule.bounds'));
            }
        });
    }
}
