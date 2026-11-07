<?php

declare(strict_types=1);

namespace Modules\Workshop\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Optional parameters of the folder actions: status change, quote, invoice.
 */
final class FolderActionRequest extends FormRequest
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
            'status' => ['sometimes', 'string', 'in:in_progress,completed,delivered,cancelled'],
            'mileage_out' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'valid_until' => ['sometimes', 'nullable', 'date'],
            'date' => ['sometimes', 'date'],
        ];
    }
}
