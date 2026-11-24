<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Procurement\Enums\InvoiceMatchStatus;
use Modules\Procurement\Models\InvoiceMatchingRun;
use Modules\Procurement\Support\Decimal;

/**
 * Reads of the matching workbench: the current runs with their filters, the detail of a run and the dashboard figures.
 */
final class InvoiceMatchingQueryService
{
    /**
     * @param  array{status?: string|null, match_type?: string|null, supplier_id?: string|null, search?: string|null, queue?: string|null}  $filters
     * @return LengthAwarePaginator<int, InvoiceMatchingRun>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = InvoiceMatchingRun::query()
            ->with(['invoice.supplier', 'purchaseOrder'])
            ->where('is_current', true)
            ->when(($filters['queue'] ?? null) === 'blocked', static fn (Builder $q) => $q->whereIn('status', InvoiceMatchStatus::blockingValues()))
            ->when(filled($filters['status'] ?? null), static fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(filled($filters['match_type'] ?? null), static fn (Builder $q) => $q->where('match_type', $filters['match_type']))
            ->when(filled($filters['supplier_id'] ?? null), static fn (Builder $q) => $q->where('supplier_id', $filters['supplier_id']))
            ->when(filled($filters['search'] ?? null), static function (Builder $q) use ($filters): void {
                $term = '%'.mb_strtolower((string) $filters['search']).'%';
                $q->where(static fn (Builder $inner) => $inner
                    ->whereHas('invoice', static fn (Builder $invoice) => $invoice->whereRaw('LOWER(invoice_number) LIKE ?', [$term]))
                    ->orWhereHas('purchaseOrder', static fn (Builder $order) => $order->whereRaw('LOWER(po_number) LIKE ?', [$term])));
            })
            ->orderByDesc('evaluated_at');

        return $query->paginate($perPage);
    }

    public function find(string $id): InvoiceMatchingRun
    {
        return InvoiceMatchingRun::query()
            ->with(['invoice.supplier', 'purchaseOrder', 'lines', 'resolutions'])
            ->findOrFail($id);
    }

    /**
     * Run history of an invoice, newest first.
     *
     * @return Collection<int, InvoiceMatchingRun>
     */
    public function historyOf(string $invoiceId): Collection
    {
        return InvoiceMatchingRun::query()->with('purchaseOrder')->where('passive_invoice_id', $invoiceId)->orderByDesc('evaluated_at')->get();
    }

    /**
     * Figures of the dashboard: the current runs by status, the invoice value waiting in the blocked queue, and the
     * share of the runs that cleared without a person (matched or within tolerance).
     *
     * @return array{total: int, by_status: array<string, int>, blocked_count: int, blocked_amount: string, auto_match_rate: float, gr_ir_cleared_amount: string}
     */
    public function summary(): array
    {
        $runs = InvoiceMatchingRun::query()->where('is_current', true)->get(['status', 'invoiced_total', 'gr_ir_amount', 'released_at']);

        $byStatus = [];
        foreach (InvoiceMatchStatus::cases() as $status) {
            $byStatus[$status->value] = 0;
        }

        $blockedAmount = '0.00';
        $grIr = '0.00';

        foreach ($runs as $run) {
            $byStatus[$run->status->value]++;

            if ($run->status->isBlocking()) {
                $blockedAmount = Decimal::add($blockedAmount, (string) $run->invoiced_total, 2);
            } else {
                $grIr = Decimal::add($grIr, (string) $run->gr_ir_amount, 2);
            }
        }

        $total = $runs->count();
        $automatic = $byStatus[InvoiceMatchStatus::Matched->value] + $byStatus[InvoiceMatchStatus::WithinTolerance->value];

        return [
            'total' => $total,
            'by_status' => $byStatus,
            'blocked_count' => $byStatus[InvoiceMatchStatus::BlockedDiscrepancy->value] + $byStatus[InvoiceMatchStatus::PendingCreditNote->value],
            'blocked_amount' => $blockedAmount,
            'auto_match_rate' => $total === 0 ? 0.0 : round($automatic * 100 / $total, 1),
            'gr_ir_cleared_amount' => $grIr,
        ];
    }
}
