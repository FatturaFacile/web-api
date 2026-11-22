<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of the closing of a phase: an optional posting date for the confirmation.
 */
final class ClosePhaseRequest extends MesCommandRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->commandRules(),
            'posting_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
