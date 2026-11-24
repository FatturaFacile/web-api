<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Presentation\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ArtisanalGelato\Domain\Enums\RecipeCategory;

/**
 * Create or replace a recipe: header, lines (an ingredient or a sub-recipe each) and the overrun used for the cost per litre.
 */
final class SaveGelatoRecipeRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'code' => ['required', 'string', 'max:48', Rule::unique('gelato_balanced_recipes', 'code')->where('tenant_id', $tenantId)->whereNull('deleted_at')->ignore($this->route('id'))],
            'name' => ['required', 'array'],
            'name.it' => ['required', 'string', 'max:160'],
            'name.de' => ['required', 'string', 'max:160'],
            'name.fr' => ['required', 'string', 'max:160'],
            'name.en' => ['required', 'string', 'max:160'],
            'category' => ['required', Rule::enum(RecipeCategory::class)],
            'kind' => ['required', Rule::in(['base_mix', 'flavor', 'sorbet', 'semifreddo', 'ganache', 'paste', 'variegato'])],
            'batch_weight_grams' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'serving_portion_grams' => ['nullable', 'numeric', 'gt:0', 'max:2000'],
            'tub_weight_grams' => ['nullable', 'numeric', 'gt:0', 'max:20000'],
            'overrun_percent' => ['nullable', 'numeric', 'min:0', 'max:200'],
            'is_defrosted' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.ingredient_id' => ['nullable', 'uuid', 'required_without:lines.*.sub_recipe_id', 'prohibits:lines.*.sub_recipe_id'],
            'lines.*.sub_recipe_id' => ['nullable', 'uuid', 'required_without:lines.*.ingredient_id'],
            'lines.*.grams' => ['required', 'numeric', 'gt:0', 'max:1000000'],
        ];
    }
}
