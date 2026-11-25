<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The checklist definition of one envelope.
 */
final class SaveChecklistRequest extends FormRequest
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
            'items' => ['required', 'array', 'max:60'],
            'items.*.key' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/', 'distinct'],
            'items.*.label' => ['nullable', 'string', 'max:160'],
            'items.*.required' => ['sometimes', 'boolean'],
            'items.*.signature_required' => ['sometimes', 'boolean'],
        ];
    }
}
