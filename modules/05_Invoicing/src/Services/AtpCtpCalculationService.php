<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services;

use App\Models\Tenant;
use App\Services\Tenancy\TenantClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Time-phased availability to promise (ATP) and capable to promise (CTP) of a product in a warehouse.
 *
 * ATP(t) = available now (on hand net of the reservations already held, which is the committed sales demand)
 *        + confirmed inbound purchase order quantities due by t
 *        + scheduled production receipts due by t.
 * When even the scheduled supply does not cover the quantity and the product has a bill of materials, the CTP date is
 * today plus its manufacturing lead time. Quantities are decimal strings with 4 decimals.
 */
final readonly class AtpCtpCalculationService
{
    public const string AvailableOnTime = 'available_on_time';

    public const string PartialAvailability = 'partial_availability';

    public const string DelayedAtp = 'delayed_atp';

    public const string CtpRequired = 'ctp_required';

    public const string Shortage = 'shortage';

    public const string FullImmediate = 'full_immediate';

    public const string SplitPartialAndBackorder = 'split_partial_and_backorder';

    public const string WaitForAtpDate = 'wait_for_atp_date';

    public const string TriggerSupply = 'trigger_procurement_or_production';

    /**
     * Purchase order statuses whose open quantities count as confirmed inbound supply.
     *
     * @var list<string>
     */
    private const array InboundStatuses = ['approved', 'sent', 'partially_received'];

    /**
     * @var list<string>
     */
    private const array ProductionStatuses = ['planned', 'released', 'in_progress'];

    public function __construct(
        private SalesOrderStockReservations $stock,
        private TenantClock $clock,
    ) {}

    /**
     * @return array{product_id: string, warehouse_id: ?string, requested_quantity: string, available_now_qty: string, shortage_qty: string, earliest_atp_date: ?string, ctp_estimated_date: ?string, atp_status: string, fulfillment_suggestion: string, stock_relevant: bool}
     */
    public function check(string $tenantId, string $productId, string|int|float $quantity, ?string $warehouseId = null, ?string $requestedDate = null): array
    {
        $required = bcadd((string) $quantity, '0', 4);
        $today = $this->clock->today(Tenant::query()->find($tenantId));
        $warehouse = $this->stock->warehouseFor($warehouseId);
        $onHand = $this->stock->availableQuantity($productId, $tenantId, $warehouse);

        if ($onHand === null) {
            // A product without inventory tracking (service, non-stock item) is always promisable.
            return $this->result($productId, $warehouse?->id, $required, $required, '0.0000', $today->toDateString(), null, self::AvailableOnTime, self::FullImmediate, false);
        }

        $availableNow = bccomp($onHand, $required, 4) >= 0 ? $required : bcadd($onHand, '0', 4);
        $shortage = bcsub($required, $availableNow, 4);

        if (bccomp($shortage, '0', 4) <= 0) {
            return $this->result($productId, $warehouse?->id, $required, $availableNow, '0.0000', $today->toDateString(), null, self::AvailableOnTime, self::FullImmediate, true);
        }

        $earliest = $this->earliestCoveringDate($tenantId, $productId, $warehouse?->id, $onHand, $required, $today);
        $ctp = $earliest === null ? $this->ctpDate($tenantId, $productId, $today) : null;
        $hasStock = bccomp($availableNow, '0', 4) > 0;
        $requested = $requestedDate !== null ? CarbonImmutable::parse($requestedDate) : null;

        if ($earliest !== null) {
            $onTime = $requested === null || $earliest->lessThanOrEqualTo($requested);
            $status = $onTime ? self::AvailableOnTime : self::DelayedAtp;
            $suggestion = $hasStock ? self::SplitPartialAndBackorder : self::WaitForAtpDate;
        } elseif ($ctp !== null) {
            $status = self::CtpRequired;
            $suggestion = $hasStock ? self::SplitPartialAndBackorder : self::TriggerSupply;
        } else {
            $status = $hasStock ? self::PartialAvailability : self::Shortage;
            $suggestion = $hasStock ? self::SplitPartialAndBackorder : self::TriggerSupply;
        }

        return $this->result($productId, $warehouse?->id, $required, $availableNow, $shortage, $earliest?->toDateString(), $ctp?->toDateString(), $status, $suggestion, true);
    }

    /**
     * Time-phased supply of a product: due date => quantity, ascending by date.
     *
     * @return array<string, string>
     */
    public function supplyTimeline(string $tenantId, string $productId, ?string $warehouseId = null): array
    {
        $buckets = [];

        $inbound = DB::table('purchase_order_items as i')
            ->join('purchase_orders as o', 'o.id', '=', 'i.purchase_order_id')
            ->where('o.tenant_id', $tenantId)
            ->where('i.product_id', $productId)
            ->whereIn('o.status', self::InboundStatuses)
            ->whereNull('i.deleted_at')
            ->whereNull('o.deleted_at')
            ->get(['i.quantity', 'i.quantity_received', 'o.expected_delivery_date', 'o.delivery_due_date']);

        foreach ($inbound as $row) {
            $date = $row->expected_delivery_date ?? $row->delivery_due_date;
            $open = bcsub((string) $row->quantity, (string) $row->quantity_received, 4);

            if ($date !== null && bccomp($open, '0', 4) > 0) {
                $key = substr((string) $date, 0, 10);
                $buckets[$key] = bcadd($buckets[$key] ?? '0', $open, 4);
            }
        }

        $production = DB::table('mfg_production_orders as p')
            ->join('mfg_items as m', 'm.id', '=', 'p.mfg_item_id')
            ->where('p.tenant_id', $tenantId)
            ->where('m.product_id', $productId)
            ->whereIn('p.status', self::ProductionStatuses)
            ->when($warehouseId !== null, static fn ($query) => $query->where(static fn ($inner) => $inner->whereNull('p.receipt_warehouse_id')->orWhere('p.receipt_warehouse_id', $warehouseId)))
            ->whereNull('p.deleted_at')
            ->get(['p.target_quantity', 'p.completed_quantity', 'p.planned_end_date']);

        foreach ($production as $row) {
            $open = bcsub((string) $row->target_quantity, (string) $row->completed_quantity, 4);

            if ($row->planned_end_date !== null && bccomp($open, '0', 4) > 0) {
                $key = substr((string) $row->planned_end_date, 0, 10);
                $buckets[$key] = bcadd($buckets[$key] ?? '0', $open, 4);
            }
        }

        ksort($buckets);

        return $buckets;
    }

    private function earliestCoveringDate(string $tenantId, string $productId, ?string $warehouseId, string $onHand, string $required, CarbonImmutable $today): ?CarbonImmutable
    {
        $cumulative = bcadd($onHand, '0', 4);

        foreach ($this->supplyTimeline($tenantId, $productId, $warehouseId) as $date => $quantity) {
            $cumulative = bcadd($cumulative, $quantity, 4);

            if (bccomp($cumulative, $required, 4) >= 0) {
                $due = CarbonImmutable::parse($date);

                return $due->lessThan($today) ? $today : $due;
            }
        }

        return null;
    }

    /**
     * Capable to promise: only for a manufactured product (it has a default bill of materials), from its lead time.
     */
    private function ctpDate(string $tenantId, string $productId, CarbonImmutable $today): ?CarbonImmutable
    {
        $leadTime = DB::table('mfg_items')
            ->where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->whereNotNull('default_bom_id')
            ->whereNull('deleted_at')
            ->value('lead_time_days');

        return $leadTime === null ? null : $today->addDays(max(0, (int) $leadTime));
    }

    /**
     * @return array{product_id: string, warehouse_id: ?string, requested_quantity: string, available_now_qty: string, shortage_qty: string, earliest_atp_date: ?string, ctp_estimated_date: ?string, atp_status: string, fulfillment_suggestion: string, stock_relevant: bool}
     */
    private function result(string $productId, ?string $warehouseId, string $required, string $availableNow, string $shortage, ?string $earliest, ?string $ctp, string $status, string $suggestion, bool $stockRelevant): array
    {
        return [
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'requested_quantity' => $required,
            'available_now_qty' => $availableNow,
            'shortage_qty' => $shortage,
            'earliest_atp_date' => $earliest,
            'ctp_estimated_date' => $ctp,
            'atp_status' => $status,
            'fulfillment_suggestion' => $suggestion,
            'stock_relevant' => $stockRelevant,
        ];
    }
}
