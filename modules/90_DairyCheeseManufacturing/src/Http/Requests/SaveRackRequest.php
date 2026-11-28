<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Requests;

final class SaveRackRequest extends DairyFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'room' => ['required', 'string', 'max:60'],
            'rack' => ['required', 'string', 'max:60'],
            'shelf' => ['required', 'string', 'max:60'],
            'level' => ['required', 'integer', 'between:1,99'],
            'capacity_wheels' => ['required', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
