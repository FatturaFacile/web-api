<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Requests;

final class MoveWheelRequest extends DairyFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rack_id' => ['required', 'uuid', $this->existsIn('dairy_aging_racks')],
            'position' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
