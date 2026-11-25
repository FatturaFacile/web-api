<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\FranchiseNetwork\Domain\Enums\SellOutSource;
use Modules\FranchiseNetwork\Http\Requests\Concerns\ScopesToTenant;

final class ImportSellOutRequest extends FormRequest
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
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'rows' => ['required', 'array', 'min:1', 'max:1000'],
            'rows.*.store_code' => ['required', 'string', 'max:30'],
            'rows.*.gross_sales' => ['required', 'numeric', 'min:0'],
            'rows.*.returns' => ['sometimes', 'numeric', 'min:0'],
            'rows.*.discounts' => ['sometimes', 'numeric', 'min:0'],
            'rows.*.tickets_count' => ['sometimes', 'integer', 'min:0'],
            'rows.*.visitors_count' => ['sometimes', 'integer', 'min:0'],
            'rows.*.cost_of_goods_sold' => ['sometimes', 'numeric', 'min:0'],
            'rows.*.average_inventory_value' => ['sometimes', 'numeric', 'min:0'],
            'rows.*.source' => ['sometimes', Rule::in(SellOutSource::values())],
        ];
    }
}
