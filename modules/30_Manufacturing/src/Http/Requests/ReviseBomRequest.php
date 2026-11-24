<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of a new revision copied from an existing one, with optional component changes.
 */
final class ReviseBomRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'new_revision' => ['nullable', 'string', 'max:20'],
            'reason' => ['required', 'string', 'max:1000'],
            'effective_from' => ['nullable', 'date'],
            'components' => ['nullable', 'array', 'max:500'],
            ...$this->componentChangeRules('components'),
        ];
    }
}
