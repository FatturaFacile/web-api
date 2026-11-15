<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Requests;

/**
 * Payload to score a customer: the analyst gives the 0-100 value of every manual factor.
 */
final class ScoreRequest extends CreditFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'uuid', $this->ownRow('contacts')],
            'manual_inputs' => ['nullable', 'array'],
            'manual_inputs.*' => ['numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
