<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\CpqConfigurator\Models\CpqConstraintRule;

/**
 * A constraint rule of a model.
 */
final class RuleRequest extends CpqFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [$this->presence(), 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/', $this->uniqueInModel(CpqConstraintRule::class, $this->modelIdOf(CpqConstraintRule::class))],
            'rule_type' => [$this->presence(), Rule::in(CpqConstraintRule::Types)],
            'condition_expression' => ['nullable', 'string', 'max:2000'],
            'target_characteristic' => ['nullable', 'string', 'max:40'],
            'target_options' => ['nullable', 'array', 'max:100'],
            'target_options.*' => ['string', 'max:40'],
            'forced_value' => ['nullable'],
            'params' => ['nullable', 'array'],
            ...$this->i18n('message', false, 1000),
            'severity' => ['sometimes', Rule::in(CpqConstraintRule::Severities)],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
