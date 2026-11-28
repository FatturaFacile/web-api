<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Requests;

final class SaveBreederRequest extends DairyFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40', $this->uniqueIn('dairy_breeders', 'code')],
            'name' => ['required', 'string', 'max:200'],
            'jurisdiction' => ['required', 'in:CH,IT'],
            'contact_id' => ['nullable', 'uuid', $this->existsIn('contacts')],
            'farm_code' => ['nullable', 'string', 'max:40'],
            'tvd_number' => ['nullable', 'string', 'max:40'],
            'bdm_number' => ['nullable', 'string', 'max:40'],
            'asl_code' => ['nullable', 'string', 'max:40'],
            'cuaa' => ['nullable', 'string', 'max:20'],
            'municipality' => ['nullable', 'string', 'max:120'],
            'canton_or_province' => ['nullable', 'string', 'max:10'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
