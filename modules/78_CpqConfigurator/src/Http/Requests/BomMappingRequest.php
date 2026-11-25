<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Http\Requests;

use Modules\CpqConfigurator\Models\CpqSuperBomMapping;

/**
 * A line of the dynamic super-BOM.
 */
final class BomMappingRequest extends CpqFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [$this->presence(), 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/', $this->uniqueInModel(CpqSuperBomMapping::class, $this->modelIdOf(CpqSuperBomMapping::class))],
            'item_code' => [$this->presence(), 'string', 'max:50'],
            ...$this->i18n('item_name', true),
            'uom' => ['sometimes', 'string', 'max:20'],
            'condition_expression' => ['nullable', 'string', 'max:2000'],
            'quantity_formula' => ['sometimes', 'string', 'max:2000'],
            'unit_cost' => ['sometimes', 'numeric', 'min:0', 'max:99999999999'],
            'unit_cost_formula' => ['nullable', 'string', 'max:2000'],
            'lead_time_days' => ['sometimes', 'integer', 'min:0', 'max:3650'],
            ...$this->i18n('auto_include_message', false, 1000),
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
