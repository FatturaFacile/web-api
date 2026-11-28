<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Requests;

final class CompleteVatBatchRequest extends DairyFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fresh_yield_24h_kg' => ['required', 'numeric', 'gt:0'],
            'plaque_prefix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-]*$/'],
            'plaque_from' => ['required', 'integer', 'min:1', 'max:99999999'],
            'plaque_to' => ['required', 'integer', 'gte:plaque_from', 'max:99999999'],
            'wheels' => ['nullable', 'array'],
            'wheels.*.weight_kg' => ['required_with:wheels', 'numeric', 'gt:0', 'max:200'],
            'wheels_count' => ['nullable', 'integer', 'min:1'],
            'initial_weight_kg' => ['nullable', 'numeric', 'gt:0', 'max:200', 'required_without:wheels'],
            'rack_id' => ['nullable', 'uuid', $this->existsIn('dairy_aging_racks')],
        ];
    }
}
