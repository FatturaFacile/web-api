<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The flavors of the showcase wells (16 or 24 slots) and the jurisdiction whose allergen rules the book follows.
 */
final class PublishGelatoShowcaseRequest extends FormRequest
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
            'jurisdiction' => ['nullable', Rule::in(['IT', 'CH'])],
            'slots' => ['required', 'array', 'min:1', 'max:24'],
            'slots.*.slot_number' => ['required', 'integer', 'min:1', 'max:24'],
            'slots.*.recipe_id' => ['required', 'uuid'],
        ];
    }
}
