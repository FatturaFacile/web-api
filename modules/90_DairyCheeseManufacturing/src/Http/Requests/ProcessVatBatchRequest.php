<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Requests;

final class ProcessVatBatchRequest extends DairyFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'starter_culture' => ['nullable', 'string', 'max:120'],
            'starter_amount' => ['nullable', 'numeric', 'min:0'],
            'rennet_type' => ['nullable', 'string', 'max:120'],
            'rennet_ml' => ['nullable', 'numeric', 'min:0'],
            'ph_log' => ['nullable', 'array', 'max:200'],
            'ph_log.*.at' => ['required', 'date'],
            'ph_log.*.ph' => ['required', 'numeric', 'between:3,8'],
            'ph_log.*.temperature_c' => ['nullable', 'numeric', 'between:0,100'],
            'curd_yield_kg' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
