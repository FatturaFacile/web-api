<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SavePriceItemRequest extends FormRequest
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
            'position_code' => ['required', 'string', 'max:40', Rule::unique('ct_price_items', 'position_code')->where('price_list_id', $this->route('id'))],
            'parent_code' => ['nullable', 'string', 'max:40'],
            'unit' => ['required', 'string', 'max:10'],
            'unit_price' => ['required', 'decimal:0,4', 'min:0'],
            'labour_minutes' => ['nullable', 'decimal:0,2', 'min:0'],
            'material_cost' => ['nullable', 'decimal:0,4', 'min:0'],
            'machine_cost' => ['nullable', 'decimal:0,4', 'min:0'],
            'descriptions' => ['required', 'array:de,fr,it,en', 'min:1'],
            'descriptions.*' => ['string', 'max:4000'],
        ];
    }
}
