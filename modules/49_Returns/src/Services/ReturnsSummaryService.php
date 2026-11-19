<?php

declare(strict_types=1);

namespace Modules\Returns\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Returns\Models\Rma;
use Modules\Returns\Models\SupplierReturn;
use Modules\Returns\Models\Warranty;
use Modules\Returns\Models\WarrantyClaim;
use Modules\Returns\Support\Decimal;

/**
 * The returns dashboard: return rate by product and by reason, the ageing of the open returns and the value refunded.
 * Everything is computed with portable queries over the returns requested in the period.
 */
final readonly class ReturnsSummaryService
{
    /**
     * Age buckets of the open returns in days since the request: key => [from, to or null for no upper bound].
     *
     * @var array<string, array{0: int, 1: int|null}>
     */
    private const array AgeingBuckets = ['0_7' => [0, 7], '8_30' => [8, 30], '31_60' => [31, 60], 'over_60' => [61, null]];

    /**
     * @return array<string, mixed>
     */
    public function summarize(string $tenantId, ?string $from, ?string $to): array
    {
        $from ??= now()->subYear()->toDateString();
        $to ??= now()->toDateString();
        $counted = static fn ($query) => $query
            ->where('returns_rmas.tenant_id', $tenantId)
            ->whereNull('returns_rmas.deleted_at')
            ->whereIn('returns_rmas.status', Rma::CountingStatuses)
            ->whereBetween('returns_rmas.requested_on', [$from, $to]);

        return [
            'period' => ['from' => $from, 'to' => $to],
            'totals' => $this->totals($tenantId, $from, $to),
            'by_reason' => $this->byReason($counted),
            'by_product' => $this->byProduct($tenantId, $from, $to, $counted),
            'ageing' => $this->ageing($tenantId),
            'by_status' => $this->byStatus($tenantId, $from, $to),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function totals(string $tenantId, string $from, string $to): array
    {
        $inPeriod = Rma::query()->where('tenant_id', $tenantId)->whereBetween('requested_on', [$from, $to]);
        $refunded = (clone $inPeriod)->whereIn('status', [Rma::Resolved, Rma::Closed])->whereNotNull('refund_amount')->sum('refund_amount');

        return [
            'rma_count' => (clone $inPeriod)->count(),
            'open_rma_count' => Rma::query()->where('tenant_id', $tenantId)->whereIn('status', Rma::OpenStatuses)->count(),
            'refund_value' => Decimal::round($refunded, 2),
            'open_warranty_claims' => WarrantyClaim::query()->where('tenant_id', $tenantId)->where('status', WarrantyClaim::Open)->count(),
            'active_warranties' => Warranty::query()->where('tenant_id', $tenantId)->where('status', Warranty::Active)->where('ends_on', '>=', now()->toDateString())->count(),
            'open_supplier_returns' => SupplierReturn::query()->where('tenant_id', $tenantId)->whereIn('status', [SupplierReturn::Draft, SupplierReturn::Shipped, SupplierReturn::Credited])->count(),
            'supplier_credit_expected' => Decimal::round(SupplierReturn::query()->where('tenant_id', $tenantId)->whereIn('status', [SupplierReturn::Draft, SupplierReturn::Shipped])->sum('credit_expected'), 2),
        ];
    }

    /**
     * @return list<array{reason_code: string, rma_count: int, quantity: string}>
     */
    private function byReason(callable $counted): array
    {
        return DB::table('returns_rmas')
            ->join('returns_rma_lines', static fn ($join) => $join->on('returns_rma_lines.rma_id', '=', 'returns_rmas.id')->whereNull('returns_rma_lines.deleted_at'))
            ->tap($counted)
            ->groupBy('returns_rmas.reason_code')
            ->orderByDesc(DB::raw('SUM(returns_rma_lines.quantity)'))
            ->orderBy('returns_rmas.reason_code')
            ->get([
                'returns_rmas.reason_code',
                DB::raw('COUNT(DISTINCT returns_rmas.id) AS rma_count'),
                DB::raw('SUM(returns_rma_lines.quantity) AS quantity'),
            ])
            ->map(static fn (object $row): array => [
                'reason_code' => (string) $row->reason_code,
                'rma_count' => (int) $row->rma_count,
                'quantity' => Decimal::round($row->quantity, 4),
            ])
            ->all();
    }

    /**
     * Returned quantity of every product against the quantity invoiced in the same period.
     *
     * @return list<array{product_id: string, sku: string|null, name: string|null, returned_quantity: string, sold_quantity: string, return_rate: string|null}>
     */
    private function byProduct(string $tenantId, string $from, string $to, callable $counted): array
    {
        $returned = DB::table('returns_rmas')
            ->join('returns_rma_lines', static fn ($join) => $join->on('returns_rma_lines.rma_id', '=', 'returns_rmas.id')->whereNull('returns_rma_lines.deleted_at'))
            ->tap($counted)
            ->groupBy('returns_rma_lines.product_id')
            ->get(['returns_rma_lines.product_id', DB::raw('SUM(returns_rma_lines.quantity) AS quantity')]);

        if ($returned->isEmpty()) {
            return [];
        }

        $productIds = $returned->pluck('product_id')->all();
        $sold = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.tenant_id', $tenantId)
            ->whereNull('invoices.deleted_at')
            ->whereNotIn('invoices.invoice_type', ['credit_note', 'proforma'])
            ->whereBetween('invoices.date', [$from, $to])
            ->whereIn('invoice_items.product_id', $productIds)
            ->groupBy('invoice_items.product_id')
            ->pluck(DB::raw('SUM(invoice_items.quantity) AS quantity'), 'invoice_items.product_id');
        $products = DB::table('products')->where('tenant_id', $tenantId)->whereIn('id', $productIds)->get(['id', 'sku', 'name'])->keyBy('id');

        return $returned
            ->map(static function (object $row) use ($sold, $products): array {
                $soldQuantity = Decimal::of($sold[$row->product_id] ?? 0);
                $returnedQuantity = Decimal::of($row->quantity);

                return [
                    'product_id' => (string) $row->product_id,
                    'sku' => $products[$row->product_id]->sku ?? null,
                    'name' => $products[$row->product_id]->name ?? null,
                    'returned_quantity' => Decimal::round($returnedQuantity, 4),
                    'sold_quantity' => Decimal::round($soldQuantity, 4),
                    'return_rate' => Decimal::isPositive($soldQuantity) ? Decimal::round(Decimal::mul(Decimal::div($returnedQuantity, $soldQuantity), '100'), 2) : null,
                ];
            })
            ->sortByDesc(static fn (array $row): float => (float) $row['returned_quantity'])
            ->values()
            ->all();
    }

    /**
     * Open returns by age since the request.
     *
     * @return list<array{bucket: string, count: int}>
     */
    private function ageing(string $tenantId): array
    {
        $today = Carbon::parse(now()->toDateString());
        $counts = array_fill_keys(array_keys(self::AgeingBuckets), 0);

        foreach (Rma::query()->where('tenant_id', $tenantId)->whereIn('status', Rma::OpenStatuses)->pluck('requested_on') as $requestedOn) {
            $age = (int) Carbon::parse((string) $requestedOn)->diffInDays($today);

            foreach (self::AgeingBuckets as $bucket => [$min, $max]) {
                if ($age >= $min && ($max === null || $age <= $max)) {
                    $counts[$bucket]++;
                    break;
                }
            }
        }

        $result = [];

        foreach ($counts as $bucket => $count) {
            $result[] = ['bucket' => $bucket, 'count' => $count];
        }

        return $result;
    }

    /**
     * @return array<string, int>
     */
    private function byStatus(string $tenantId, string $from, string $to): array
    {
        $counts = array_fill_keys(Rma::Statuses, 0);

        foreach (Rma::query()->where('tenant_id', $tenantId)->whereBetween('requested_on', [$from, $to])->selectRaw('status, COUNT(*) AS total')->groupBy('status')->get() as $row) {
            $counts[(string) $row->status] = (int) $row->getAttribute('total');
        }

        return $counts;
    }
}
