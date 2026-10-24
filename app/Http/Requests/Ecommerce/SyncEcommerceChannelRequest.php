<?php

declare(strict_types=1);

namespace App\Http\Requests\Ecommerce;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncEcommerceChannelRequest extends FormRequest
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
            'filters' => ['nullable', 'array', 'min:1'],
            'filters.*' => ['string', Rule::in(['orders', 'stock', 'products'])],
        ];
    }

    /**
     * The requested synchronisations; orders and stock when none are named.
     *
     * @return array<int, string>
     */
    public function filters(): array
    {
        return array_values(array_unique((array) $this->input('filters', ['orders', 'stock'])));
    }
}
