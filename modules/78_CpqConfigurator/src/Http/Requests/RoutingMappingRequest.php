<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Http\Requests;

use Modules\CpqConfigurator\Models\CpqRoutingMapping;

/**
 * An operation of the dynamic routing.
 */
final class RoutingMappingRequest extends CpqFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [$this->presence(), 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/', $this->uniqueInModel(CpqRoutingMapping::class, $this->modelIdOf(CpqRoutingMapping::class))],
            ...$this->i18n('operation_name', true),
            'work_center' => [$this->presence(), 'string', 'max:50'],
            'condition_expression' => ['nullable', 'string', 'max:2000'],
            'setup_hours_formula' => ['sometimes', 'string', 'max:2000'],
            'run_hours_formula' => ['sometimes', 'string', 'max:2000'],
            'hourly_rate' => ['sometimes', 'numeric', 'min:0', 'max:99999999'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
