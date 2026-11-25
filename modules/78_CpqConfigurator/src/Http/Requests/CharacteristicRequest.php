<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\CpqConfigurator\Models\CpqCharacteristic;

/**
 * A characteristic of a model: a value list, a numeric range or a boolean.
 */
final class CharacteristicRequest extends CpqFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $presence = $this->presence();

        return [
            'code' => [$presence, 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', $this->uniqueInModel(CpqCharacteristic::class, $this->modelIdOf(CpqCharacteristic::class))],
            'step_code' => ['nullable', 'string', 'max:40'],
            'type' => [$presence, Rule::in(CpqCharacteristic::Types)],
            ...$this->i18n('label', true),
            ...$this->i18n('help', false, 1000),
            'unit' => ['nullable', 'string', 'max:20'],
            'range_min' => ['nullable', 'numeric'],
            'range_max' => ['nullable', 'numeric', 'gte:range_min'],
            'range_step' => ['nullable', 'numeric', 'gt:0'],
            'default_value' => ['nullable', 'string', 'max:100'],
            'is_required' => ['sometimes', 'boolean'],
            'part_number' => ['nullable', 'array'],
            'part_number.position' => ['nullable', 'integer', 'min:1', 'max:30'],
            'part_number.prefix' => ['nullable', 'string', 'max:6'],
            'part_number.true_code' => ['nullable', 'string', 'max:12'],
            'part_number.false_code' => ['nullable', 'string', 'max:12'],
            'visual' => ['nullable', 'array'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
