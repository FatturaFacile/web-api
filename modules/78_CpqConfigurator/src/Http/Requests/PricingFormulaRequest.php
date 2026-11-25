<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\CpqConfigurator\Models\CpqPricingFormula;

/**
 * A pricing component of a model.
 */
final class PricingFormulaRequest extends CpqFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [$this->presence(), 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/', $this->uniqueInModel(CpqPricingFormula::class, $this->modelIdOf(CpqPricingFormula::class))],
            ...$this->i18n('label', true),
            'effect' => [$this->presence(), Rule::in(CpqPricingFormula::Effects)],
            'condition_expression' => ['nullable', 'string', 'max:2000'],
            'formula' => [$this->presence(), 'string', 'max:2000'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
