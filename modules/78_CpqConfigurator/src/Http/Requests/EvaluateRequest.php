<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Http\Requests;

/**
 * A selection to evaluate, confirm or print: the answers by characteristic code, the quantity and an extra discount.
 * One request serves the live evaluation, the confirmation and the proposal.
 */
final class EvaluateRequest extends CpqFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'selections' => ['present', 'array', 'max:200'],
            'quantity' => ['sometimes', 'numeric', 'gt:0', 'max:1000000'],
            'discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'customer_reference' => ['nullable', 'string', 'max:190'],
            'include_breakdown' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function selections(): array
    {
        return (array) $this->validated('selections', []);
    }

    public function quantity(): float
    {
        return (float) ($this->validated('quantity') ?? 1);
    }

    public function discount(): float
    {
        return (float) ($this->validated('discount_percent') ?? 0);
    }
}
