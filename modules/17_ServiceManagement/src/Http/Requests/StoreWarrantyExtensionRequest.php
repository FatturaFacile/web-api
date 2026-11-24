<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

final class StoreWarrantyExtensionRequest extends WarrantyRmaFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'months' => ['required', 'integer', 'between:1,120'],
            'starts_at' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
        ];
    }
}
