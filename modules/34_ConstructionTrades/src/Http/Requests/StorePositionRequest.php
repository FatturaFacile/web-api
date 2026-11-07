<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A position from a price list item, a work cycle, or entered by hand.
 */
final class StorePositionRequest extends FormRequest
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
        $manual = ! $this->filled('price_item_id') && ! $this->filled('work_cycle_id');

        return [
            'price_item_id' => ['nullable', 'uuid', 'prohibits:work_cycle_id', $this->tenantExists('ct_price_items')],
            'work_cycle_id' => ['nullable', 'uuid', $this->tenantExists('ct_work_cycles')],
            'position_code' => [$manual || $this->filled('work_cycle_id') ? 'required' : 'nullable', 'string', 'max:40'],
            'description' => [$manual ? 'required' : 'nullable', 'string', 'max:4000'],
            'unit' => [$manual ? 'required' : 'nullable', 'string', 'max:10'],
            'quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'unit_price' => [$manual ? 'required' : 'nullable', 'decimal:0,4', 'min:0'],
            'labour_minutes' => ['nullable', 'decimal:0,2', 'min:0'],
            'material_cost' => ['nullable', 'decimal:0,4', 'min:0'],
            'machine_cost' => ['nullable', 'decimal:0,4', 'min:0'],
        ];
    }
}
