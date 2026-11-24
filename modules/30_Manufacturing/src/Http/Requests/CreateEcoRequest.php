<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of a new engineering change on a released BOM revision.
 */
final class CreateEcoRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bom_id' => ['required', 'uuid', $this->tenantOwns('mfg_boms')],
            'title' => ['required', 'string', 'max:200'],
            'reason' => ['required', 'string', 'max:5000'],
            'description' => ['nullable', 'string', 'max:5000'],
            'change_type' => ['nullable', 'string', 'in:bom_change,component_substitution,quantity_change'],
            'effective_date' => ['nullable', 'date', 'after_or_equal:today'],
            'new_revision' => ['nullable', 'string', 'max:20'],
            'components' => ['required', 'array', 'min:1', 'max:500'],
            ...$this->componentChangeRules('components'),
        ];
    }
}
