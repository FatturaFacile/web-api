<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of the optional document date of a posting (default today).
 */
final class PostingDateRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'posting_date' => ['nullable', 'date'],
        ];
    }
}
