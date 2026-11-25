<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use Illuminate\Support\Facades\DB;
use Modules\Procurement\Enums\InvoiceMatchStatus;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Support\Decimal;

/**
 * Composite supplier rating (0-100 score and A-D tier) computed from the executed purchase-to-pay documents.
 *
 * Components and weights:
 * - on-time delivery (35%) and quality / conformity (30%), from goods receipts against the order promise and inspections;
 * - price accuracy (15%): ordered value against the supplier price list;
 * - invoice accuracy (10%): share of matched invoices that cleared the 3-way match without a blocking exception;
 * - RFQ responsiveness (10%): share of invited tenders answered with a quote.
 * A component without data is left out and the remaining weights are renormalised, so a new supplier is not penalised.
 */
final readonly class SupplierRatingService
{
    /** @var array<string, int> */
    public const array Weights = [
        'on_time_delivery_rate' => 35,
        'quality_acceptance_rate' => 30,
        'price_competitiveness_score' => 15,
        'invoice_accuracy_rate' => 10,
        'rfq_responsiveness_rate' => 10,
    ];

    /** @var list<string> */
    public const array SortFields = ['score', 'name', 'on_time_delivery_rate', 'quality_acceptance_rate', 'price_competitiveness_score', 'invoice_accuracy_rate', 'rfq_responsiveness_rate'];

    public function __construct(private SupplierPerformanceService $performance) {}

    /**
     * @return array{supplier_id: string, supplier_name: string, qualification_status: string, period: array{from: string, to: string}, components: array<string, ?string>, weights: array<string, int>, score: ?string, tier: ?string, sample: array{receipt_lines: int, order_lines: int, matched_invoices: int, blocked_invoices: int, rfq_invitations: int, rfq_responses: int}}
     */
    public function rate(Supplier $supplier, string $from, string $to): array
    {
        $metrics = $this->performance->metrics($supplier, $from, $to);
        $invoices = $this->invoiceAccuracy((string) $supplier->tenant_id, (string) $supplier->id, $from, $to);
        $rfq = $this->rfqResponsiveness((string) $supplier->tenant_id, (string) $supplier->id, $from, $to);

        $components = [
            'on_time_delivery_rate' => $metrics['on_time_delivery_rate'],
            'quality_acceptance_rate' => $metrics['quality_acceptance_rate'],
            'price_competitiveness_score' => $metrics['price_competitiveness_score'],
            'invoice_accuracy_rate' => $invoices['rate'],
            'rfq_responsiveness_rate' => $rfq['rate'],
        ];

        $score = self::composite($components);

        return [
            'supplier_id' => (string) $supplier->id,
            'supplier_name' => (string) $supplier->company_name,
            'qualification_status' => (string) $supplier->qualification_status,
            'period' => ['from' => $from, 'to' => $to],
            'components' => $components,
            'weights' => self::Weights,
            'score' => $score,
            'tier' => $score === null ? null : self::tier($score),
            'sample' => [
                'receipt_lines' => $metrics['receipt_lines'],
                'order_lines' => $metrics['order_lines'],
                'matched_invoices' => $invoices['total'],
                'blocked_invoices' => $invoices['blocked'],
                'rfq_invitations' => $rfq['invited'],
                'rfq_responses' => $rfq['responded'],
            ],
        ];
    }

    /**
     * Ratings of the suppliers that were ordered from, invoiced or invited in the period, best first. Suppliers without any
     * data have no score and come last.
     *
     * @return list<array<string, mixed>>
     */
    public function ranking(string $tenantId, string $from, string $to, ?int $limit = null): array
    {
        $active = DB::table('purchase_orders')->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->distinct()->pluck('supplier_id')->all();
        $invited = DB::table('procurement_rfq_suppliers')->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->whereDate('invited_at', '>=', $from)->whereDate('invited_at', '<=', $to)->distinct()->pluck('supplier_id')->all();

        $ids = array_values(array_unique([...$active, ...$invited]));

        if ($ids === []) {
            return [];
        }

        $ratings = Supplier::query()->whereIn('id', $ids)->get()
            ->map(fn (Supplier $supplier): array => $this->rate($supplier, $from, $to))
            ->all();

        usort($ratings, static function (array $a, array $b): int {
            if ($a['score'] === null || $b['score'] === null) {
                return ($a['score'] === null) <=> ($b['score'] === null);
            }

            return Decimal::cmp($b['score'], $a['score'], 2) ?: strcmp($a['supplier_name'], $b['supplier_name']);
        });

        return $limit === null ? $ratings : array_slice($ratings, 0, max(1, $limit));
    }

    /**
     * One page of the ranking after filtering by supplier name and tier and sorting by score, name or a component.
     * Suppliers without a value for the sort field always come last. The ratings are computed from the documents of the
     * period, so the whole active set is rated before the page is cut.
     *
     * @param  array{search: ?string, tier: ?string, sort: string, direction: string, page: int, per_page: int}  $listing
     * @return array{data: list<array<string, mixed>>, meta: array{current_page: int, per_page: int, total: int, last_page: int, from: ?int, to: ?int}}
     */
    public function page(string $tenantId, string $from, string $to, array $listing): array
    {
        $rows = $this->ranking($tenantId, $from, $to);

        if ($listing['search'] !== null && trim($listing['search']) !== '') {
            $term = mb_strtolower(trim($listing['search']));
            $rows = array_values(array_filter($rows, static fn (array $row): bool => str_contains(mb_strtolower((string) $row['supplier_name']), $term)));
        }

        if ($listing['tier'] !== null) {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['tier'] === $listing['tier']));
        }

        $descending = $listing['direction'] === 'desc';
        $value = static fn (array $row): ?string => match ($listing['sort']) {
            'name' => (string) $row['supplier_name'],
            'score' => $row['score'],
            default => $row['components'][$listing['sort']] ?? null,
        };

        usort($rows, static function (array $a, array $b) use ($value, $descending, $listing): int {
            [$left, $right] = [$value($a), $value($b)];

            if ($left === null || $right === null) {
                return ($left === null) <=> ($right === null);
            }

            $order = $listing['sort'] === 'name' ? strcasecmp($left, $right) : Decimal::cmp($left, $right, 2);

            return ($descending ? -$order : $order) ?: strcasecmp((string) $a['supplier_name'], (string) $b['supplier_name']);
        });

        $total = count($rows);
        $perPage = max(1, $listing['per_page']);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $current = min(max(1, $listing['page']), $lastPage);
        $offset = ($current - 1) * $perPage;
        $data = array_slice($rows, $offset, $perPage);

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $current,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $data === [] ? null : $offset + 1,
                'to' => $data === [] ? null : $offset + count($data),
            ],
        ];
    }

    /**
     * Weighted 0-100 score over the available components.
     *
     * @param  array<string, ?string>  $components
     */
    public static function composite(array $components): ?string
    {
        $weighted = '0';
        $weights = 0;

        foreach (self::Weights as $key => $weight) {
            $value = $components[$key] ?? null;

            if ($value === null) {
                continue;
            }

            $weighted = Decimal::add($weighted, Decimal::mul($value, (string) $weight, 4), 4);
            $weights += $weight;
        }

        return $weights === 0 ? null : Decimal::div($weighted, (string) $weights, 2);
    }

    public static function tier(string $score): string
    {
        return match (true) {
            Decimal::cmp($score, '90', 2) >= 0 => 'A',
            Decimal::cmp($score, '75', 2) >= 0 => 'B',
            Decimal::cmp($score, '60', 2) >= 0 => 'C',
            default => 'D',
        };
    }

    /**
     * Share of invoices of the supplier whose current match did not end in a blocking exception.
     *
     * @return array{rate: ?string, total: int, blocked: int}
     */
    private function invoiceAccuracy(string $tenantId, string $supplierId, string $from, string $to): array
    {
        $runs = DB::table('invoice_matching_runs')->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('is_current', true)
            ->where('supplier_id', $supplierId)
            ->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)
            ->pluck('status');

        $blocking = [InvoiceMatchStatus::BlockedDiscrepancy->value, InvoiceMatchStatus::PendingCreditNote->value];
        $blocked = $runs->filter(static fn (mixed $status): bool => in_array((string) $status, $blocking, true))->count();
        $total = $runs->count();

        return [
            'rate' => $total > 0 ? Decimal::percentOf((string) ($total - $blocked), (string) $total, 2) : null,
            'total' => $total,
            'blocked' => $blocked,
        ];
    }

    /**
     * Share of tender invitations of the period answered with a submitted quote.
     *
     * @return array{rate: ?string, invited: int, responded: int}
     */
    private function rfqResponsiveness(string $tenantId, string $supplierId, string $from, string $to): array
    {
        $invitations = DB::table('procurement_rfq_suppliers')->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->where('supplier_id', $supplierId)
            ->whereDate('invited_at', '>=', $from)->whereDate('invited_at', '<=', $to)
            ->get(['status', 'submitted_at', 'responded_at']);

        $responded = $invitations->filter(static fn (object $row): bool => $row->submitted_at !== null || ($row->responded_at !== null && $row->status !== 'declined'))->count();
        $invited = $invitations->count();

        return [
            'rate' => $invited > 0 ? Decimal::percentOf((string) $responded, (string) $invited, 2) : null,
            'invited' => $invited,
            'responded' => $responded,
        ];
    }
}
