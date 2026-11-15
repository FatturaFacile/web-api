<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Requests;

/**
 * Payload to run the next dunning step of a case.
 */
final class AdvanceCaseRequest extends CreditFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'outcome' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
