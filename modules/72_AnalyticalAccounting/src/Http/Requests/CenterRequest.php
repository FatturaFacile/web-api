<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\AnalyticalAccounting\Domain\Enums\CenterCategory;
use Modules\AnalyticalAccounting\Domain\Enums\CenterType;
use Modules\AnalyticalAccounting\Domain\Enums\CostElementType;

/**
 * Validation of a cost or profit center payload; every reference is limited to the active company.
 */
final class CenterRequest extends AnalyticalFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('id');
        $update = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $required = $update ? 'sometimes' : 'required';

        return [
            'parent_id' => ['nullable', 'uuid', $this->owned('analytical_centers'), Rule::notIn([$id])],
            'code' => [$required, 'string', 'max:40', Rule::unique('analytical_centers', 'code')->where('tenant_id', $this->tenantId())->whereNull('deleted_at')->ignore($id)],
            'name' => [$required, 'string', 'max:160'],
            'name_translations' => ['nullable', 'array'],
            'name_translations.*' => ['string', 'max:160'],
            'center_type' => [$required, Rule::in(CenterType::values())],
            'category' => [$required, Rule::in(CenterCategory::values())],
            'manager_user_id' => ['nullable', 'uuid'],
            'manager_name' => ['nullable', 'string', 'max:160'],
            'is_active' => ['sometimes', 'boolean'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'default_profit_center_id' => ['nullable', 'uuid', $this->owned('analytical_centers')],
            'default_project_id' => ['nullable', 'uuid'],
            'default_product_line_id' => ['nullable', 'uuid'],
            'default_cost_element_type' => ['nullable', Rule::in(CostElementType::values())],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
