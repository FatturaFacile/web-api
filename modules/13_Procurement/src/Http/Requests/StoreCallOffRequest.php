<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A call-off order on a framework agreement.
 */
final class StoreCallOffRequest extends FormRequest
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
            'order_date' => ['nullable', 'date_format:Y-m-d'],
            'expected_delivery_date' => ['required', 'date_format:Y-m-d'],
            'cost_center_id' => ['nullable', 'uuid', $this->tenantExists('cost_centers')],
            'project_id' => ['nullable', 'uuid', $this->tenantOwns('projects')],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.supplier_contract_line_id' => ['required', 'uuid'],
            'lines.*.quantity_ordered' => ['required', 'decimal:0,4', 'gt:0'],
            'lines.*.expected_delivery_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
