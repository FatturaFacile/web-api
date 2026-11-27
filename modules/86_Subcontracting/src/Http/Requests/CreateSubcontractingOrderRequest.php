<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Subcontracting\Domain\Enums\CustomsProcessingRegime;
use Modules\Subcontracting\Domain\Enums\Direction;
use Modules\Subcontracting\Domain\Enums\SubcontractingType;
use Modules\Subcontracting\Models\SubcontractingOrder;

final class CreateSubcontractingOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', SubcontractingOrder::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::enum(Direction::class)],
            'subcontracting_type' => ['required', Rule::enum(SubcontractingType::class)],
            'partner_id' => ['required', 'uuid'],
            'output_product_id' => ['required', 'uuid'],
            'planned_quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'unit_service_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'currency' => ['nullable', 'string', 'size:3'],
            'expected_return_date' => ['nullable', 'date'],
            'bom_id' => ['nullable', 'uuid'],
            'linked_production_order_id' => ['nullable', 'uuid'],
            'linked_production_order_operation_id' => ['nullable', 'uuid'],
            'routing_operation_label' => ['nullable', 'string', 'max:200'],
            'source_warehouse_id' => ['nullable', 'uuid'],
            'customs_regime' => ['nullable', Rule::enum(CustomsProcessingRegime::class)],
            'customs_declaration_id' => ['nullable', 'uuid'],
            'linked_service_purchase_order_id' => ['nullable', 'uuid'],
            'linked_customer_sales_order_id' => ['nullable', 'uuid'],
            'generate_service_purchase_order' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'components' => ['nullable', 'array', 'max:200'],
            'components.*.component_product_id' => ['required', 'uuid'],
            'components.*.unit_of_measure' => ['nullable', 'string', 'max:20'],
            'components.*.required_qty_per_unit' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'components.*.lot_id' => ['nullable', 'uuid'],
            'components.*.serial_number' => ['nullable', 'string', 'max:100'],
        ];
    }
}
