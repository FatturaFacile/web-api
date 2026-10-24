<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class ConvertToDeliveryNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lines' => ['nullable', 'array'],
            'lines.*' => ['numeric', 'gt:0'],
            'transport_reason' => ['nullable', 'string', 'max:100'],
            'goods_appearance' => ['nullable', 'string', 'max:100'],
            'packages_count' => ['nullable', 'integer', 'min:1'],
            'gross_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'carrier_name' => ['nullable', 'string', 'max:255'],
            'courier' => ['nullable', 'string', 'max:255'],
            'shipping_address' => ['nullable', 'string', 'max:1000'],
            'show_prices' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
