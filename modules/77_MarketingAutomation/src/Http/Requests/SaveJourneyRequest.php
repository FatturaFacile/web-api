<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\MarketingAutomation\Domain\Enums\JourneyStepType;

final class SaveJourneyRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Role access to the module is enforced by the module.access middleware of the route group.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'steps' => ['required', 'array', 'min:1', 'max:60'],
            'steps.*.step_key' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9_\-]+$/', 'distinct'],
            'steps.*.type' => ['required', Rule::in(JourneyStepType::values())],
            'steps.*.config' => ['nullable', 'array'],
            'steps.*.next_step_key' => ['nullable', 'string', 'max:60'],
            'steps.*.true_step_key' => ['nullable', 'string', 'max:60'],
            'steps.*.false_step_key' => ['nullable', 'string', 'max:60'],
            'steps.*.is_entry' => ['sometimes', 'boolean'],
            'steps.*.pos_x' => ['sometimes', 'integer'],
            'steps.*.pos_y' => ['sometimes', 'integer'],
        ];
    }

    /**
     * Every edge must point at a step of the same journey and exactly one step is the entry.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $steps = (array) $this->input('steps', []);
            $keys = array_column($steps, 'step_key');

            foreach ($steps as $i => $step) {
                foreach (['next_step_key', 'true_step_key', 'false_step_key'] as $edge) {
                    if (! empty($step[$edge]) && ! in_array($step[$edge], $keys, true)) {
                        $validator->errors()->add("steps.$i.$edge", 'unknown_step');
                    }
                }
                if (($step['type'] ?? '') === 'condition' && (empty($step['true_step_key']) || empty($step['false_step_key']))) {
                    $validator->errors()->add("steps.$i.type", 'condition_needs_both_branches');
                }
            }

            if (count(array_filter($steps, static fn (array $s): bool => (bool) ($s['is_entry'] ?? false))) !== 1) {
                $validator->errors()->add('steps', 'exactly_one_entry');
            }
        }];
    }
}
