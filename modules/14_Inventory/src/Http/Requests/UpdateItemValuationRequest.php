<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\DTOs\ItemValuationUpdateDTO;
use Modules\Inventory\Enums\ValuationMethod;
use Modules\Inventory\Support\Decimal;

final class UpdateItemValuationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Explicit null = inherit from the category default.
            'valuation_method' => ['sometimes', 'nullable', 'string', Rule::in(ValuationMethod::acceptedInputValues())],
            'standard_cost' => ['sometimes', 'numeric', 'min:0'],
            'weight_kg' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'volume_m3' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'revalue_on_change' => ['sometimes', 'boolean'],
        ];
    }

    public function toDTO(string $itemId): ItemValuationUpdateDTO
    {
        $method = $this->input('valuation_method');

        return new ItemValuationUpdateDTO(
            itemId: $itemId,
            methodProvided: $this->exists('valuation_method'),
            valuationMethod: is_string($method) ? ValuationMethod::fromStorage($method) : null,
            standardCost: $this->filled('standard_cost') ? Decimal::of((string) $this->input('standard_cost')) : null,
            weightProvided: $this->exists('weight_kg'),
            weightKg: $this->filled('weight_kg') ? Decimal::of((string) $this->input('weight_kg')) : null,
            volumeProvided: $this->exists('volume_m3'),
            volumeM3: $this->filled('volume_m3') ? Decimal::of((string) $this->input('volume_m3')) : null,
            revalueOnChange: $this->has('revalue_on_change') ? $this->boolean('revalue_on_change') : true,
            userId: $this->user()?->getAuthIdentifier() !== null ? (string) $this->user()->getAuthIdentifier() : null,
        );
    }
}
