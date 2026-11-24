<?php

declare(strict_types=1);

namespace Modules\Wms\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Wms\Models\WmsDockDoor;

/**
 * Payload to create or change a dock door.
 */
final class DockDoorRequest extends WmsFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'code' => ['required', 'string', 'max:30', $this->uniqueIn('wms_dock_doors', 'code', ['warehouse_id'])],
            'name' => ['nullable', 'string', 'max:120'],
            'direction' => ['sometimes', Rule::in(WmsDockDoor::Directions)],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        return $this->isMethod('POST') ? [
            'warehouse_id' => ['required', 'uuid', $this->ownRow('warehouses')],
            ...$rules,
        ] : $rules;
    }
}
