<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of a routing revision copy (the next revision when none is given).
 */
final class CloneRoutingRevisionRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'revision' => ['nullable', 'string', 'max:20'],
        ];
    }
}
