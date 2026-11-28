<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Requests;

final class SaveQualityTestRequest extends DairyFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'breeder_id' => ['required', 'uuid', $this->existsIn('dairy_breeders')],
            'delivery_id' => ['nullable', 'uuid', $this->existsIn('dairy_milk_deliveries')],
            'sample_date' => ['required', 'date'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'fat_pct' => ['nullable', 'numeric', 'between:0,15'],
            'protein_pct' => ['nullable', 'numeric', 'between:0,12'],
            'casein_pct' => ['nullable', 'numeric', 'between:0,10'],
            'urea_mg_l' => ['nullable', 'numeric', 'between:0,1000'],
            'tbc_cfu_ml' => ['nullable', 'integer', 'min:0'],
            'scc_cells_ml' => ['nullable', 'integer', 'min:0'],
            'freezing_point_c' => ['nullable', 'numeric', 'between:-1.5,0'],
            'inhibitors' => ['nullable', 'in:negative,positive,not_tested'],
            'laboratory' => ['nullable', 'string', 'max:120'],
        ];
    }
}
