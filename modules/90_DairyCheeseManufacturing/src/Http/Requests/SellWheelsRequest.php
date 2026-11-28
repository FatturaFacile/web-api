<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Requests;

final class SellWheelsRequest extends DairyFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'wheel_ids' => ['required', 'array', 'min:1', 'max:200'],
            'wheel_ids.*' => ['uuid', $this->existsIn('dairy_cheese_aging_wheels')],
            'customer_id' => ['nullable', 'uuid', $this->existsIn('contacts')],
            'customer_name' => ['required', 'string', 'max:200'],
            'weights' => ['required', 'array'],
            'weights.*' => ['numeric', 'gt:0', 'max:200'],
            'invoice_ref' => ['nullable', 'string', 'max:80'],
        ];
    }
}
