<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Accepts some of the discrepant lines of a blocked invoice.
 */
final class PartialInvoiceResolutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
            'line_ids' => ['required', 'array', 'min:1'],
            'line_ids.*' => ['uuid', 'distinct'],
        ];
    }
}
