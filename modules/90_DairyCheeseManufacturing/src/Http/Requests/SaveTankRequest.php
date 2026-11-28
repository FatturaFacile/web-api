<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Requests;

final class SaveTankRequest extends DairyFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40', $this->uniqueIn('dairy_milk_tanks', 'code')],
            'name' => ['required', 'string', 'max:200'],
            'capacity_liters' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'status' => ['sometimes', 'in:active,cleaning,out_of_service'],
        ];
    }
}
