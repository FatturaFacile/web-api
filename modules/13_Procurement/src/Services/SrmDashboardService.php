<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use App\Enums\PurchaseOrderStatus;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Enums\InvoiceMatchStatus;
use Modules\Procurement\Enums\PurchaseRequisitionStatus;
use Modules\Procurement\Support\Decimal;

/**
 * Executive overview of purchasing and supplier relationships: what waits for a decision (requisitions, tenders), what is
 * late (orders), what waits for a check (receipts), what is blocked (invoices failing the 3-way match), where the money
 * goes (spend by supplier and category) and who performs best and worst (supplier ratings).
 */
final readonly class SrmDashboardService
{
    private const array OpenRfqStatuses = ['issued', 'published', 'bidding_open', 'under_evaluation'];

    public function __construct(
        private ProcurementAnalyticsService $analytics,
        private SupplierRatingService $ratings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(string $tenantId, string $from, string $to): array
    {
        $ranked = array_values(array_filter($this->ratings->ranking($tenantId, $from, $to), static fn (array $row): bool => $row['score'] !== null));

        return [
            'period' => ['from' => $from, 'to' => $to],
            'requisitions' => $this->requisitions($tenantId),
            'rfqs' => $this->rfqs($tenantId),
            'orders' => $this->orders($tenantId),
            'receipts' => $this->receipts($tenantId),
            'blocked_invoices' => $this->blockedInvoices($tenantId),
            'spend' => [
                'by_supplier' => array_slice($this->analytics->spend($tenantId, $from, $to, 'supplier'), 0, 5),
                'by_category' => array_slice($this->analytics->spend($tenantId, $from, $to, 'category'), 0, 5),
            ],
            'suppliers' => $this->suppliers($tenantId),
            'scorecards' => [
                'top' => array_slice($ranked, 0, 5),
                'bottom' => array_slice(array_reverse($ranked), 0, 5),
            ],
        ];
    }

    /**
     * @return array{pending_approval: int, pending_value: string, approved_waiting_sourcing: int}
     */
    private function requisitions(string $tenantId): array
    {
        $base = fn () => DB::table('purchase_requisitions')->where('tenant_id', $tenantId)->whereNull('deleted_at');

        return [
            'pending_approval' => $base()->where('status', PurchaseRequisitionStatus::PendingApproval->value)->count(),
            'pending_value' => Decimal::of((string) ($base()->where('status', PurchaseRequisitionStatus::PendingApproval->value)->sum('total_estimated_amount')), 2),
            'approved_waiting_sourcing' => $base()->where('status', PurchaseRequisitionStatus::Approved->value)->count(),
        ];
    }

    /**
     * @return array{open: int, awaiting_award: int, expiring_soon: int}
     */
    private function rfqs(string $tenantId): array
    {
        $base = fn () => DB::table('procurement_rfqs')->where('tenant_id', $tenantId)->whereNull('deleted_at');

        return [
            'open' => $base()->whereIn('status', self::OpenRfqStatuses)->count(),
            'awaiting_award' => $base()->where('status', 'under_evaluation')->count(),
            'expiring_soon' => $base()->whereIn('status', self::OpenRfqStatuses)->whereDate('response_deadline', '>=', now()->toDateString())->whereDate('response_deadline', '<=', now()->addDays(3)->toDateString())->count(),
        ];
    }

    /**
     * @return array{open: int, open_value: array<string, string>, overdue: int, overdue_value: array<string, string>, overdue_list: list<array<string, mixed>>}
     */
    private function orders(string $tenantId): array
    {
        $open = collect($this->analytics->openOrders($tenantId));
        $overdue = $open->where('is_overdue', true)->values();
        $names = DB::table('suppliers')->where('tenant_id', $tenantId)->whereIn('id', $overdue->pluck('supplier_id')->all())->pluck('company_name', 'id');

        return [
            'open' => $open->count(),
            'open_value' => $this->sumByCurrency($open->all()),
            'overdue' => $overdue->count(),
            'overdue_value' => $this->sumByCurrency($overdue->all()),
            'overdue_list' => $overdue->take(5)->map(static fn (array $row): array => [...$row, 'supplier_name' => $names[$row['supplier_id']] ?? null])->all(),
        ];
    }

    /**
     * @return array{pending_inspection: int, awaiting_invoice: int}
     */
    private function receipts(string $tenantId): array
    {
        return [
            'pending_inspection' => DB::table('purchase_receipts')->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('inspection_status', 'pending')->count(),
            'awaiting_invoice' => DB::table('purchase_order_lines as l')
                ->join('purchase_orders as po', 'po.id', '=', 'l.purchase_order_id')
                ->where('po.tenant_id', $tenantId)->whereNull('po.deleted_at')->whereNull('l.deleted_at')
                ->whereNotIn('po.status', [PurchaseOrderStatus::Cancelled->value, PurchaseOrderStatus::Rejected->value])
                ->whereColumn('l.quantity_received', '>', 'l.quantity_invoiced')
                ->distinct()->count('po.id'),
        ];
    }

    /**
     * @return array{count: int, variance_total: string, items: list<array<string, mixed>>}
     */
    private function blockedInvoices(string $tenantId): array
    {
        $blocking = [InvoiceMatchStatus::BlockedDiscrepancy->value, InvoiceMatchStatus::PendingCreditNote->value];
        $runs = DB::table('invoice_matching_runs as r')
            ->leftJoin('suppliers as s', 's.id', '=', 'r.supplier_id')
            ->leftJoin('passive_invoices as i', 'i.id', '=', 'r.passive_invoice_id')
            ->where('r.tenant_id', $tenantId)->whereNull('r.deleted_at')->where('r.is_current', true)->whereIn('r.status', $blocking)
            ->orderByDesc('r.blocked_at')
            ->get(['r.id', 'r.passive_invoice_id', 'r.purchase_order_id', 'r.status', 'r.currency', 'r.variance_amount', 'r.blocked_at', 's.company_name as supplier_name', 'i.invoice_number']);

        $total = '0';

        foreach ($runs as $run) {
            $total = Decimal::add($total, Decimal::abs((string) ($run->variance_amount ?? '0'), 2), 2);
        }

        return [
            'count' => $runs->count(),
            'variance_total' => $total,
            'items' => $runs->take(5)->map(static fn (object $run): array => [
                'run_id' => (string) $run->id,
                'passive_invoice_id' => (string) $run->passive_invoice_id,
                'purchase_order_id' => $run->purchase_order_id !== null ? (string) $run->purchase_order_id : null,
                'invoice_number' => $run->invoice_number !== null ? (string) $run->invoice_number : null,
                'supplier_name' => $run->supplier_name !== null ? (string) $run->supplier_name : null,
                'status' => (string) $run->status,
                'currency' => $run->currency !== null ? (string) $run->currency : null,
                'variance_amount' => Decimal::of((string) ($run->variance_amount ?? '0'), 2),
                'blocked_at' => $run->blocked_at !== null ? (string) $run->blocked_at : null,
            ])->all(),
        ];
    }

    /**
     * @return array{by_qualification: array<string, int>, expiring_documents: int, expired_documents: int}
     */
    private function suppliers(string $tenantId): array
    {
        $today = now()->toDateString();
        $documents = fn () => DB::table('supplier_documents')->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereNotNull('expires_at');

        return [
            'by_qualification' => DB::table('suppliers')->where('tenant_id', $tenantId)->whereNull('deleted_at')
                ->selectRaw('qualification_status as status, COUNT(*) as total')->groupBy('qualification_status')->pluck('total', 'status')
                ->map(static fn (mixed $count): int => (int) $count)->all(),
            'expiring_documents' => $documents()->whereDate('expires_at', '>=', $today)->whereDate('expires_at', '<=', now()->addDays(30)->toDateString())->count(),
            'expired_documents' => $documents()->whereDate('expires_at', '<', $today)->count(),
        ];
    }

    /**
     * Open value per order currency: values in different currencies are never added together.
     *
     * @param  list<array{currency: string, open_value: string}>  $orders
     * @return array<string, string>
     */
    private function sumByCurrency(array $orders): array
    {
        $totals = [];

        foreach ($orders as $order) {
            $currency = (string) $order['currency'];
            $totals[$currency] = Decimal::add($totals[$currency] ?? '0.00', (string) $order['open_value'], 2);
        }

        ksort($totals);

        return $totals;
    }
}
