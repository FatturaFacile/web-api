<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Validation of the GS1-128 output label query: the image format.
 */
final class OutputLabelRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'format' => ['nullable', 'string', Rule::in(['svg', 'png'])],
        ];
    }
}
