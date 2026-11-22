<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Small recipe actions: clone (code), scale (batch weight), ganache calculation (lines) and ganache spec (chocolate profile and
 * measured tempering curve). Each action reads only its own fields.
 */
final class GelatoRecipeActionRequest extends FormRequest
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
            'code' => ['nullable', 'string', 'max:48'],
            'batch_weight_grams' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'lines' => ['nullable', 'array', 'max:200'],
            'lines.*.ingredient_id' => ['nullable', 'uuid'],
            'lines.*.sub_recipe_id' => ['nullable', 'uuid'],
            'lines.*.grams' => ['required_with:lines', 'numeric', 'min:0', 'max:1000000'],
            'chocolate_type' => ['nullable', Rule::in(['dark', 'milk', 'white'])],
            'cocoa_butter_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cocoa_mass_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tempering' => ['nullable', 'array'],
            'tempering.melt' => ['required_with:tempering', 'numeric', 'min:0', 'max:80'],
            'tempering.cool' => ['required_with:tempering', 'numeric', 'min:0', 'max:80'],
            'tempering.work' => ['required_with:tempering', 'numeric', 'min:0', 'max:80'],
        ];
    }
}
