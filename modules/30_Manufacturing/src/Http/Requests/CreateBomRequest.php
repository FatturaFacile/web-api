<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateBomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mfg_item_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:200'],
            'revision' => ['nullable', 'string', 'max:20'],
            'bom_type' => ['nullable', 'string', 'in:standard,alternate,phantom'],
            'base_quantity' => ['nullable', 'numeric', 'min:0.0001'],
            'uom' => ['nullable', 'string', 'max:20'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'components' => ['required', 'array', 'min:1'],
            'components.*.component_item_id' => ['required', 'uuid'],
            'components.*.quantity_per' => ['required', 'numeric', 'min:0.0001'],
            'components.*.scrap_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'components.*.yield_percentage' => ['nullable', 'numeric', 'min:1', 'max:100'],
            'components.*.is_phantom' => ['nullable', 'boolean'],
        ];
    }
}
