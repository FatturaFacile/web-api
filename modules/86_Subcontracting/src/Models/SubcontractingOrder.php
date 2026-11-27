<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Subcontracting\Domain\Enums\CustomsProcessingRegime;
use Modules\Subcontracting\Domain\Enums\Direction;
use Modules\Subcontracting\Domain\Enums\OrderStatus;
use Modules\Subcontracting\Domain\Enums\SubcontractingType;

/**
 * Aggregate root of a conto lavoro job (passive to a vendor, active for a customer).
 *
 * @property string $order_number
 * @property Direction $direction
 * @property SubcontractingType $subcontracting_type
 * @property OrderStatus $status
 * @property string $partner_type
 * @property string $partner_id
 * @property string|null $partner_name
 * @property string|null $source_warehouse_id
 * @property string|null $subcontractor_warehouse_id
 * @property string|null $bom_id
 * @property string|null $linked_production_order_id
 * @property string|null $linked_production_order_operation_id
 * @property string|null $routing_operation_label
 * @property string|null $linked_service_purchase_order_id
 * @property string|null $linked_customer_sales_order_id
 * @property string|null $outgoing_material_ddt_id
 * @property string|null $inbound_return_document_type
 * @property string|null $inbound_return_document_id
 * @property string|null $inbound_return_document_number
 * @property string $output_product_id
 * @property string $planned_quantity
 * @property string $received_quantity
 * @property string $scrap_quantity
 * @property string $delivered_quantity
 * @property string $unit_service_cost
 * @property string $currency
 * @property Carbon|null $expected_return_date
 * @property CustomsProcessingRegime $customs_regime
 * @property string|null $customs_declaration_id
 * @property string $capitalized_service_amount
 * @property string $capitalized_unit_cost
 * @property array<string, mixed>|null $service_billing
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $completed_at
 * @property string|null $notes
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property-read Collection<int, SubcontractingOrderComponent> $components
 */
final class SubcontractingOrder extends BaseEnterpriseModel
{
    protected $table = 'sct_orders';

    protected function casts(): array
    {
        return [
            'direction' => Direction::class,
            'subcontracting_type' => SubcontractingType::class,
            'status' => OrderStatus::class,
            'customs_regime' => CustomsProcessingRegime::class,
            'planned_quantity' => 'decimal:4',
            'received_quantity' => 'decimal:4',
            'scrap_quantity' => 'decimal:4',
            'delivered_quantity' => 'decimal:4',
            'unit_service_cost' => 'decimal:4',
            'capitalized_service_amount' => 'decimal:4',
            'capitalized_unit_cost' => 'decimal:6',
            'expected_return_date' => 'date',
            'service_billing' => 'array',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<SubcontractingOrderComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(SubcontractingOrderComponent::class, 'subcontracting_order_id')->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return HasMany<SubcontractMaterialLedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(SubcontractMaterialLedgerEntry::class, 'subcontracting_order_id')->orderBy('entry_date')->orderBy('created_at');
    }

    /** Quantity of finished goods still expected back (planned less received and scrapped). */
    public function openQuantity(): string
    {
        $open = bcsub(bcsub((string) $this->planned_quantity, (string) $this->received_quantity, 4), (string) $this->scrap_quantity, 4);

        return bccomp($open, '0', 4) > 0 ? $open : '0.0000';
    }
}
