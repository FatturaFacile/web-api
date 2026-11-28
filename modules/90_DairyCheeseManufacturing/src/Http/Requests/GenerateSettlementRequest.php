<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Requests;

final class GenerateSettlementRequest extends DairyFormRequest
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
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'grid_id' => ['required', 'uuid', 'exists:dairy_milk_pricing_grids,id'],
            'breeder_ids' => ['nullable', 'array'],
            'breeder_ids.*' => ['uuid', $this->existsIn('dairy_breeders')],
        ];
    }
}
