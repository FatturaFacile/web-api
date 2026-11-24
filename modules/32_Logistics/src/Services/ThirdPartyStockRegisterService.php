<?php

declare(strict_types=1);

namespace Modules\Logistics\Services;

use Modules\Logistics\Enums\WarehouseType;
use Modules\Logistics\Queries\InventoryValuationReportQuery;

/**
 * Third-party goods register ("Registro merci presso terzi / di terzi"): goods at customers, goods at subcontractors
 * and vendor consignment held by us, each with the DDT references and fiscal values, plus the segregated valuation.
 */
final class ThirdPartyStockRegisterService
{
    public function __construct(
        private readonly ConsignmentService $consignment,
        private readonly InventoryValuationReportQuery $valuation,
    ) {}

    /**
     * @return array{
     *     goods_at_customers: list<array<string, mixed>>,
     *     goods_at_subcontractors: list<array<string, mixed>>,
     *     vendor_consignment_held: list<array<string, mixed>>,
     *     valuation: array<string, mixed>
     * }
     */
    public function register(?string $partnerId = null, ?string $from = null, ?string $to = null): array
    {
        $ledger = $this->consignment->ledger(null, $partnerId, $from, $to);
        $section = static fn (WarehouseType $type): array => array_values(array_filter(
            $ledger,
            static fn (array $entry): bool => $entry['warehouse_type'] === $type->value,
        ));

        return [
            'goods_at_customers' => $section(WarehouseType::CustomerConsignment),
            'goods_at_subcontractors' => $section(WarehouseType::Subcontractor),
            'vendor_consignment_held' => $section(WarehouseType::VendorConsignment),
            'valuation' => $this->valuation->execute(),
        ];
    }
}
