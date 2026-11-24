<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Quick note update of a deal (smartphone bottom sheet): replaces the note, or appends a timestamped line to it.
 */
final class UpdateOpportunityNotesRequest extends FormRequest
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
            'notes' => ['required', 'string', 'max:5000'],
            'append' => ['nullable', 'boolean'],
        ];
    }
}
