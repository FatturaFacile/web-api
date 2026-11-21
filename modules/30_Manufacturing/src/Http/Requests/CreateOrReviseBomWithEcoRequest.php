<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of "create a BOM draft, or revise a released BOM through an engineering change": with a source BOM the
 * components are changes (add, update, remove) to it, without one they are the full lines of a new draft.
 */
final class CreateOrReviseBomWithEcoRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $revising = $this->filled('source_bom_id');

        $rules = [
            'source_bom_id' => ['nullable', 'uuid', $this->tenantOwns('mfg_boms')],
            'title' => ['nullable', 'string', 'max:200'],
            'reason' => [$revising ? 'required' : 'nullable', 'string', 'max:1000'],
            'new_revision' => ['nullable', 'string', 'max:20'],
            'effective_from' => ['nullable', 'date'],
            'components' => [$revising ? 'nullable' : 'required', 'array', $revising ? 'min:0' : 'min:1', 'max:500'],
        ];

        if ($revising) {
            return [...$rules, ...$this->componentChangeRules('components')];
        }

        return [
            ...$rules,
            'mfg_item_id' => ['required', 'uuid', $this->tenantOwns('mfg_items')],
            'name' => ['required', 'string', 'max:200'],
            'bom_number' => ['nullable', 'string', 'max:50'],
            'revision' => ['nullable', 'string', 'max:20'],
            'bom_type' => ['nullable', 'string', 'in:standard,phantom,engineering,process_formula,disassembly'],
            'base_quantity' => ['nullable', ...self::PositiveQuantity],
            'uom' => ['nullable', 'string', 'max:20'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'components.*.component_item_id' => ['required', 'uuid', 'distinct', $this->tenantOwns('mfg_items')],
            'components.*.quantity_per' => ['required', ...self::PositiveQuantity],
            'components.*.scrap_percentage' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999'],
            'components.*.yield_percentage' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:100'],
            'components.*.is_phantom' => ['nullable', 'boolean'],
            'components.*.issue_method' => ['nullable', 'string', 'in:manual,backflush,backflush_order_close'],
            'components.*.operation_sequence' => ['nullable', 'integer', 'min:1', 'max:99999'],
            'components.*.fixed_scrap_quantity' => ['nullable', ...self::Quantity],
            'components.*.substitute_item_ids' => ['nullable', 'array', 'max:10'],
            'components.*.substitute_item_ids.*' => ['uuid', 'distinct', $this->tenantOwns('mfg_items')],
            'components.*.sequence' => ['nullable', 'integer', 'min:1', 'max:99999'],
            'components.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
