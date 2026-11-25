<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\FranchiseNetwork\Domain\Enums\SellOutSource;
use Modules\FranchiseNetwork\Http\Requests\Concerns\ScopesToTenant;

final class SaveSellOutRequest extends FormRequest
{
    use ScopesToTenant;

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
            'gross_sales' => ['required', 'numeric', 'min:0'],
            'returns' => ['sometimes', 'numeric', 'min:0'],
            'discounts' => ['sometimes', 'numeric', 'min:0'],
            'tickets_count' => ['sometimes', 'integer', 'min:0'],
            'visitors_count' => ['sometimes', 'integer', 'min:0'],
            'cost_of_goods_sold' => ['sometimes', 'numeric', 'min:0'],
            'average_inventory_value' => ['sometimes', 'numeric', 'min:0'],
            'source' => ['sometimes', Rule::in(SellOutSource::values())],
            'pos_reference' => ['nullable', 'string', 'max:80'],
        ];
    }
}
