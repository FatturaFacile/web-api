<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Requests;

final class SaveCurveRequest extends DairyFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cheese_type' => ['required', 'in:parmigiano_reggiano_dop,grana_padano_dop,gruyere_aop,sbrinz_aop,alpe_ticinese_dop,generic'],
            'name' => ['required', 'string', 'max:200'],
            'points' => ['required', 'array', 'min:1', 'max:60'],
            'points.*.age_months' => ['required', 'numeric', 'min:0', 'max:120', 'distinct'],
            'points.*.loss_pct' => ['required', 'numeric', 'between:0,60'],
        ];
    }
}
