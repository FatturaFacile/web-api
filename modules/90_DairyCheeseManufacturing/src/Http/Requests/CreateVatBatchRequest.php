<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Requests;

final class CreateVatBatchRequest extends DairyFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cheese_type' => ['required', 'in:parmigiano_reggiano_dop,grana_padano_dop,gruyere_aop,sbrinz_aop,alpe_ticinese_dop,generic'],
            'production_date' => ['required', 'date'],
            'vat_number' => ['nullable', 'string', 'max:40'],
            'tank_usages' => ['required', 'array', 'min:1', 'max:20'],
            'tank_usages.*.tank_id' => ['required', 'uuid', $this->existsIn('dairy_milk_tanks')],
            'tank_usages.*.liters' => ['required', 'numeric', 'gt:0', 'max:1000000'],
        ];
    }
}
