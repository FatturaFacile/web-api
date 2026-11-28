<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Requests;

final class WeighWheelRequest extends DairyFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['weight_kg' => ['required', 'numeric', 'gt:0', 'max:200']];
    }
}
