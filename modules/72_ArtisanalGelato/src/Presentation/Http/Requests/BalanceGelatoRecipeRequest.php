<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ArtisanalGelato\Domain\Enums\RecipeCategory;

/**
 * Preview of a recipe (lines) or auto balance (pool and targets). Exactly one of lines or targets drives the answer.
 */
final class BalanceGelatoRecipeRequest extends FormRequest
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
            'category' => ['required', Rule::enum(RecipeCategory::class)],
            'recipe_id' => ['nullable', 'uuid'],
            'lines' => ['required_without:targets', 'array', 'max:200'],
            'lines.*.ingredient_id' => ['nullable', 'uuid', 'required_without:lines.*.sub_recipe_id'],
            'lines.*.sub_recipe_id' => ['nullable', 'uuid'],
            'lines.*.grams' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'overrun_percent' => ['nullable', 'numeric', 'min:0', 'max:200'],
            'mix_density' => ['nullable', 'numeric', 'gt:0', 'max:3'],
            'serving_portion_grams' => ['nullable', 'numeric', 'gt:0', 'max:2000'],
            'tub_weight_grams' => ['nullable', 'numeric', 'gt:0', 'max:20000'],
            'targets' => ['required_without:lines', 'array'],
            'targets.weight' => ['required_with:targets', 'numeric', 'gt:0', 'max:1000000'],
            'targets.pod' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'targets.pac' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'targets.total_fat' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'targets.slng' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'targets.total_solids' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'pool' => ['required_with:targets', 'array', 'min:1', 'max:60'],
            'pool.*.ingredient_id' => ['required', 'uuid'],
            'pool.*.min_grams' => ['nullable', 'numeric', 'min:0'],
            'pool.*.max_grams' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
