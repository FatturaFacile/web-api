<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of a new BOM revision (draft) with its components; on PUT, of the changes to a draft revision.
 */
final class CreateBomRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'mfg_item_id' => [$creating ? 'required' : 'prohibited', 'uuid', $this->tenantOwns('mfg_items')],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'bom_number' => [$creating ? 'nullable' : 'prohibited', 'string', 'max:50'],
            'revision' => [$creating ? 'nullable' : 'prohibited', 'string', 'max:20'],
            'bom_type' => ['nullable', 'string', 'in:standard,phantom,engineering'],
            'base_quantity' => ['nullable', ...self::PositiveQuantity],
            'uom' => ['nullable', 'string', 'max:20'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'components' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:500'],
            'components.*.component_item_id' => ['required', 'uuid', 'distinct', $this->tenantOwns('mfg_items')],
            'components.*.quantity_per' => ['required', ...self::PositiveQuantity],
            'components.*.sequence' => ['nullable', 'integer', 'min:1', 'max:99999'],
            'components.*.scrap_percentage' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999'],
            'components.*.yield_percentage' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:100'],
            'components.*.is_phantom' => ['nullable', 'boolean'],
            'components.*.issue_method' => ['nullable', 'string', 'in:manual,backflush'],
            'components.*.operation_sequence' => ['nullable', 'integer', 'min:1', 'max:99999'],
            'components.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
