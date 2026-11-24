<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Presentation\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ArtisanalGelato\Domain\Services\AllergenCatalog;

/**
 * Technical ingredient of the catalog.
 */
final class StoreGelatoIngredientRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:48', Rule::unique('gelato_technical_ingredients', 'code')->where('tenant_id', $tenantId)->whereNull('deleted_at')->ignore($this->route('id'))],
            'name' => ['required', 'array'],
            'name.it' => ['required', 'string', 'max:160'],
            'name.de' => ['required', 'string', 'max:160'],
            'name.fr' => ['required', 'string', 'max:160'],
            'name.en' => ['required', 'string', 'max:160'],
            'category' => ['required', 'string', 'max:24'],
            'pod' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'pac' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'milk_fat_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'other_fat_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'slng_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'total_solids_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'water_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sugar_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fiber_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'protein_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'brix' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cocoa_butter_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cocoa_mass_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'kcal_per_100g' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'cost_per_kg' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'solute_class' => ['nullable', Rule::in(['none', 'sucrose', 'glucose', 'invert', 'sorbitol'])],
            'allergens' => ['nullable', 'array'],
            'allergens.*' => ['string', Rule::in(AllergenCatalog::codes())],
            'is_vegan' => ['nullable', 'boolean'],
            'is_defrosted' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'stock_item_id' => ['nullable', 'uuid', Rule::exists('stock_items', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'stock_unit_grams' => ['nullable', 'numeric', 'gt:0', 'max:100000000'],
        ];
    }
}
