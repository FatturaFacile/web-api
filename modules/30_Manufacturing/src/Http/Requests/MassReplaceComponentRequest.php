<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of the replacement of a component across the released BOMs that use it.
 */
final class MassReplaceComponentRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'obsolete_item_id' => ['required', 'uuid', $this->tenantOwns('mfg_items')],
            'substitute_item_id' => ['required', 'uuid', 'different:obsolete_item_id', $this->tenantOwns('mfg_items')],
            'reason' => ['required', 'string', 'max:1000'],
            'effective_from' => ['nullable', 'date'],
            'dry_run' => ['nullable', 'boolean'],
        ];
    }
}
