<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

final class SaveWorkCycleRequest extends FormRequest
{
    use TenantScopedRules;

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
            'name' => ['required', 'string', 'max:150'],
            'unit' => ['required', 'string', 'max:10'],
            'descriptions' => ['required', 'array:de,fr,it,en', 'min:1'],
            'descriptions.*' => ['string', 'max:4000'],
            'is_active' => ['sometimes', 'boolean'],
            'steps' => ['required', 'array', 'min:1', 'max:50'],
            'steps.*.price_item_id' => ['nullable', 'uuid', $this->tenantExists('ct_price_items')],
            'steps.*.descriptions' => ['required', 'array:de,fr,it,en', 'min:1'],
            'steps.*.descriptions.*' => ['string', 'max:4000'],
            'steps.*.quantity_factor' => ['nullable', 'decimal:0,4', 'gt:0'],
            'steps.*.unit_price' => ['required', 'decimal:0,4', 'min:0'],
            'steps.*.labour_minutes' => ['nullable', 'decimal:0,2', 'min:0'],
            'steps.*.material_cost' => ['nullable', 'decimal:0,4', 'min:0'],
            'steps.*.machine_cost' => ['nullable', 'decimal:0,4', 'min:0'],
        ];
    }
}
