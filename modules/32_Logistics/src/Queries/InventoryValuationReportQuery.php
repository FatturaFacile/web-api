<?php

declare(strict_types=1);

namespace Modules\Logistics\Queries;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\Logistics\Enums\LegalOwnerType;
use Modules\Logistics\Enums\WarehouseType;

/**
 * Fiscal inventory valuation segregated by legal ownership and physical location:
 * owned on-hand, owned in transit, owned at customer consignment, owned at subcontractor (all balance-sheet assets)
 * and third-party vendor consignment held in our premises (never an asset).
 */
final class InventoryValuationReportQuery
{
    public const BUCKET_OWNED_ON_HAND = 'owned_on_hand';

    public const BUCKET_OWNED_IN_TRANSIT = 'owned_in_transit';

    public const BUCKET_OWNED_AT_CUSTOMER = 'owned_at_customer_consignment';

    public const BUCKET_OWNED_AT_SUBCONTRACTOR = 'owned_at_subcontractor';

    public const BUCKET_VENDOR_CONSIGNMENT_HELD = 'third_party_vendor_consignment_held';

    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @return array{
     *     total_balance_sheet_value: float,
     *     excluded_third_party_value: float,
     *     buckets: array<string, float>,
     *     by_warehouse_type: array<string, float>,
     *     by_warehouse: list<array<string, mixed>>
     * }
     */
    public function execute(): array
    {
        $rows = DB::table('warehouse_stock_levels as l')
            ->join('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->where('l.tenant_id', $this->tenantContext->getTenantId())
            ->whereNull('l.deleted_at')
            ->whereNull('w.deleted_at')
            ->selectRaw('w.id as warehouse_id, w.code as warehouse_code, w.warehouse_type as warehouse_type, w.legal_owner_type as legal_owner_type, SUM(l.quantity_on_hand * p.cost_price) as value')
            ->groupBy('w.id', 'w.code', 'w.warehouse_type', 'w.legal_owner_type')
            ->get();

        $buckets = [
            self::BUCKET_OWNED_ON_HAND => 0.0,
            self::BUCKET_OWNED_IN_TRANSIT => 0.0,
            self::BUCKET_OWNED_AT_CUSTOMER => 0.0,
            self::BUCKET_OWNED_AT_SUBCONTRACTOR => 0.0,
            self::BUCKET_VENDOR_CONSIGNMENT_HELD => 0.0,
        ];
        $byType = [];
        $byWarehouse = [];
        foreach ($rows as $row) {
            $value = round((float) $row->value, 2);
            $bucket = $this->bucketFor((string) $row->warehouse_type, (string) $row->legal_owner_type);
            $buckets[$bucket] = round($buckets[$bucket] + $value, 2);
            $onBalanceSheet = $bucket !== self::BUCKET_VENDOR_CONSIGNMENT_HELD;
            if ($onBalanceSheet) {
                $byType[$row->warehouse_type] = round(($byType[$row->warehouse_type] ?? 0.0) + $value, 2);
            }
            $byWarehouse[] = [
                'warehouse_id' => $row->warehouse_id,
                'warehouse_code' => $row->warehouse_code,
                'warehouse_type' => $row->warehouse_type,
                'legal_owner_type' => $row->legal_owner_type,
                'bucket' => $bucket,
                'value' => $value,
                'on_balance_sheet' => $onBalanceSheet,
            ];
        }

        $excluded = $buckets[self::BUCKET_VENDOR_CONSIGNMENT_HELD];

        return [
            'total_balance_sheet_value' => round(array_sum($buckets) - $excluded, 2),
            'excluded_third_party_value' => $excluded,
            'buckets' => $buckets,
            'by_warehouse_type' => $byType,
            'by_warehouse' => $byWarehouse,
        ];
    }

    private function bucketFor(string $warehouseType, string $legalOwnerType): string
    {
        if ($warehouseType === WarehouseType::VendorConsignment->value || $legalOwnerType !== LegalOwnerType::Company->value) {
            return self::BUCKET_VENDOR_CONSIGNMENT_HELD;
        }

        return match ($warehouseType) {
            WarehouseType::Transit->value => self::BUCKET_OWNED_IN_TRANSIT,
            WarehouseType::CustomerConsignment->value => self::BUCKET_OWNED_AT_CUSTOMER,
            WarehouseType::Subcontractor->value => self::BUCKET_OWNED_AT_SUBCONTRACTOR,
            default => self::BUCKET_OWNED_ON_HAND,
        };
    }
}
