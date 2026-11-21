<?php

declare(strict_types=1);

namespace App\Domain\Procurement\PurchaseRequisition\Models;

use App\Domain\Procurement\PurchaseRequisition\Enums\RequisitionLineConversionStatus;
use App\Domain\Procurement\PurchaseRequisition\Enums\RequisitionLineItemType;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Procurement\Models\SupplierCatalog;
use Modules\Procurement\Support\Decimal;
use Modules\Projects\Models\Project;

/**
 * Line item within a Mod 044 Purchase Requisition supporting catalog references, free-text items, cost center/project overrides, and partial conversion tracking.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $purchase_requisition_id
 * @property int $line_number
 * @property string $item_type
 * @property string|null $catalog_item_id
 * @property string|null $supplier_catalog_id
 * @property string $description
 * @property string|null $quantity
 * @property string $quantity_requested
 * @property string|null $unit_of_measure
 * @property string $uom
 * @property string $estimated_unit_price
 * @property string|null $estimated_line_total
 * @property string $estimated_total_price
 * @property string|null $cost_center_id
 * @property string|null $project_id
 * @property string $converted_quantity
 * @property string $conversion_status
 * @property string $quantity_quoted
 * @property string $quantity_ordered
 * @property string $sourcing_status
 * @property string|null $suggested_supplier_id
 * @property-read PurchaseRequisition|null $requisition
 * @property-read Project|null $project
 * @property-read SupplierCatalog|null $supplierCatalog
 * @property-read Collection<int, PurchaseRequisitionDocumentLink> $documentLinks
 */
final class PurchaseRequisitionLine extends BaseEnterpriseModel
{
    protected $table = 'purchase_requisition_lines';

    protected static function booted(): void
    {
        self::saving(static function (PurchaseRequisitionLine $line): void {
            if ($line->quantity !== null && (string) $line->quantity !== '') {
                $line->quantity_requested = $line->quantity;
            } elseif ($line->quantity_requested !== null && (string) $line->quantity_requested !== '') {
                $line->quantity = $line->quantity_requested;
            }

            $uom = trim((string) ($line->unit_of_measure ?? $line->uom ?? ''));
            if ($uom === '') {
                $uom = 'PCS';
            }
            $line->unit_of_measure = $uom;
            $line->uom = $uom;

            if ($line->estimated_line_total !== null && (string) $line->estimated_line_total !== '') {
                $line->estimated_total_price = $line->estimated_line_total;
            } elseif ($line->estimated_total_price !== null && (string) $line->estimated_total_price !== '') {
                $line->estimated_line_total = $line->estimated_total_price;
            } else {
                $computed = Decimal::mul(
                    Decimal::of((string) ($line->quantity ?? $line->quantity_requested ?? '0'), 4),
                    Decimal::of((string) ($line->estimated_unit_price ?? '0'), 4),
                    2
                );
                $line->estimated_line_total = $computed;
                $line->estimated_total_price = $computed;
            }

            if (empty($line->item_type)) {
                $line->item_type = ($line->catalog_item_id !== null || $line->supplier_catalog_id !== null)
                    ? RequisitionLineItemType::CatalogItem->value
                    : RequisitionLineItemType::FreeText->value;
            }

            if (empty($line->conversion_status)) {
                $line->conversion_status = RequisitionLineConversionStatus::Unconverted->value;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity' => 'decimal:4',
            'quantity_requested' => 'decimal:4',
            'estimated_unit_price' => 'decimal:4',
            'estimated_line_total' => 'decimal:2',
            'estimated_total_price' => 'decimal:2',
            'converted_quantity' => 'decimal:4',
            'quantity_quoted' => 'decimal:4',
            'quantity_ordered' => 'decimal:4',
        ];
    }

    public function effectiveQuantity(): string
    {
        return Decimal::of((string) ($this->quantity ?? $this->quantity_requested ?? '0'), 4);
    }

    public function effectiveUnitOfMeasure(): string
    {
        return (string) ($this->unit_of_measure ?? $this->uom ?? 'PCS');
    }

    public function effectiveLineTotal(): string
    {
        return Decimal::of((string) ($this->estimated_line_total ?? $this->estimated_total_price ?? '0'), 2);
    }

    public function remainingConvertibleQuantity(): string
    {
        $remaining = Decimal::sub(
            $this->effectiveQuantity(),
            Decimal::of((string) ($this->converted_quantity ?? '0'), 4),
            4
        );

        return Decimal::cmp($remaining, '0', 4) > 0 ? $remaining : '0.0000';
    }

    /**
     * @return BelongsTo<PurchaseRequisition, $this>
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * @return BelongsTo<SupplierCatalog, $this>
     */
    public function supplierCatalog(): BelongsTo
    {
        return $this->belongsTo(SupplierCatalog::class, 'supplier_catalog_id');
    }

    /**
     * @return HasMany<PurchaseRequisitionDocumentLink, $this>
     */
    public function documentLinks(): HasMany
    {
        return $this->hasMany(PurchaseRequisitionDocumentLink::class, 'purchase_requisition_line_id');
    }
}
