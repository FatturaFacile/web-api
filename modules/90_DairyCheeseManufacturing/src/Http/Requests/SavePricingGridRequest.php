<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Requests;

final class SavePricingGridRequest extends DairyFormRequest
{
    protected function ability(): string
    {
        return 'settle';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'jurisdiction' => ['required', 'in:CH,IT'],
            'cheese_type' => ['nullable', 'in:parmigiano_reggiano_dop,grana_padano_dop,gruyere_aop,sbrinz_aop,alpe_ticinese_dop,generic'],
            'base_price_per_kg' => ['required', 'numeric', 'min:0', 'max:1000'],
            'cheese_milk_supplement_per_kg' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'dop_premium_per_kg' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['sometimes', 'boolean'],
            'tiers' => ['nullable', 'array', 'max:100'],
            'tiers.*.component' => ['required', 'in:fat,protein,tbc,scc,inhibitor,freezing_point,casein,urea'],
            'tiers.*.from_value' => ['nullable', 'numeric'],
            'tiers.*.to_value' => ['nullable', 'numeric'],
            'tiers.*.adjustment_per_kg' => ['required', 'numeric', 'between:-1000,1000'],
        ];
    }
}
