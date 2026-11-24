<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use Illuminate\Support\Facades\DB;
use Modules\Procurement\DTOs\CreatePurchaseOrderDTO;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\SupplierCatalog;
use Modules\Procurement\Models\SupplierContract;
use Modules\Procurement\Models\SupplierContractLine;
use Modules\Procurement\Support\Decimal;

/**
 * Framework (blanket) agreements: agreed prices with a validity and an optional committed quantity per item, consumed
 * by call-off orders. A call-off is a purchase order on the agreement; its lines take the agreed price valid on the
 * order date and cannot exceed the remaining quantity of the item or the remaining amount of the agreement.
 */
final readonly class FrameworkAgreementService
{
    public function __construct(private ProcurementService $procurement) {}

    /**
     * Adds an item to the agreement.
     *
     * @param  array{description: string, unit_price: numeric-string|float|int, price_valid_from: string, price_valid_to?: ?string, supplier_catalog_id?: ?string, uom?: ?string, tax_rate?: numeric-string|float|int|null, committed_quantity?: numeric-string|float|int|null}  $data
     *
     * @throws ProcurementRuleViolation when the catalogue entry belongs to another supplier or the validity leaves the agreement
     */
    public function addLine(SupplierContract $contract, array $data): SupplierContractLine
    {
        return DB::transaction(function () use ($contract, $data): SupplierContractLine {
            $locked = SupplierContract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();

            if (! empty($data['supplier_catalog_id']) && ! SupplierCatalog::query()->whereKey($data['supplier_catalog_id'])->where('supplier_id', $locked->supplier_id)->exists()) {
                throw new ProcurementRuleViolation('The catalogue entry belongs to another supplier.', 'supplier_catalog_id');
            }

            $validTo = $data['price_valid_to'] ?? null;

            if ($data['price_valid_from'] < $locked->starts_at->toDateString() || ($validTo ?? $locked->ends_at->toDateString()) > $locked->ends_at->toDateString()) {
                throw new ProcurementRuleViolation('The price validity must stay within the agreement period.', 'price_valid_from');
            }

            return SupplierContractLine::create([
                'tenant_id' => $locked->tenant_id,
                'supplier_contract_id' => $locked->id,
                'supplier_catalog_id' => $data['supplier_catalog_id'] ?? null,
                'description' => $data['description'],
                'uom' => $data['uom'] ?? 'PCE',
                'unit_price' => Decimal::of($data['unit_price'], 4),
                'tax_rate' => Decimal::of($data['tax_rate'] ?? '22', 2),
                'committed_quantity' => isset($data['committed_quantity']) ? Decimal::of($data['committed_quantity'], 4) : null,
                'released_quantity' => '0',
                'price_valid_from' => $data['price_valid_from'],
                'price_valid_to' => $validTo,
            ]);
        });
    }

    /**
     * Creates a call-off order on the agreement.
     *
     * @param  array{order_date?: ?string, expected_delivery_date: string, cost_center_id?: ?string, project_id?: ?string, notes?: ?string, lines: list<array{supplier_contract_line_id: string, quantity_ordered: numeric-string|float|int, expected_delivery_date?: ?string}>}  $data
     */
    public function callOff(SupplierContract $contract, array $data, ?string $userId): PurchaseOrder
    {
        return $this->procurement->createPurchaseOrder(new CreatePurchaseOrderDTO(
            supplierId: (string) $contract->supplier_id,
            orderDate: $data['order_date'] ?? now()->toDateString(),
            expectedDeliveryDate: $data['expected_delivery_date'],
            lines: $data['lines'],
            contractId: (string) $contract->id,
            projectId: $data['project_id'] ?? null,
            costCenterId: $data['cost_center_id'] ?? null,
            orderType: 'blanket_release',
            notes: $data['notes'] ?? "Call-off of agreement {$contract->contract_number}",
            tenantId: (string) $contract->tenant_id,
            creatorUserId: $userId,
        ));
    }
}
