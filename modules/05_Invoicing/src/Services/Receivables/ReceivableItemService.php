<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services\Receivables;

use App\Enums\InvoiceStatus;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Models\ReceivableAllocation;
use Modules\Invoicing\Models\ReceivableItem;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Services\InvoiceInstallmentService;
use Modules\SDI\Enums\TipoDocumento;

/**
 * Keeps the open items of the customers (`receivable_items`) in line with the issued invoices: one item per due date of
 * an invoice, settled by the paid total of the invoice (mirrored as `sync` allocations) and by the manual allocations
 * of ReceivableReconciliationService. The sync is idempotent and runs when an invoice is saved; `syncTenant` backfills
 * the invoices that have no items yet.
 */
final class ReceivableItemService
{
    public function __construct(
        private readonly InvoiceInstallmentService $installments,
        private readonly ReceivableReconciliationService $reconciliation,
    ) {}

    /**
     * Creates, updates or removes the open items of an invoice from its schedule and its paid total.
     *
     * @return list<ReceivableItem> the items of the invoice after the sync (empty when it is not a receivable)
     */
    public function syncInvoice(SalesInvoice $invoice): array
    {
        return DB::transaction(function () use ($invoice): array {
            if (! $this->isReceivable($invoice)) {
                ReceivableItem::withoutGlobalScope(TenantScope::class)->where('tenant_id', $invoice->tenant_id)->where('invoice_id', $invoice->id)->get()->each->delete();

                return [];
            }

            $tenantId = (string) $invoice->tenant_id;
            $type = TipoDocumento::ofInvoice($invoice) === TipoDocumento::Td05 ? 'debit_note' : 'invoice';
            $items = [];

            foreach ($this->installments->scheduleOf($invoice) as $row) {
                $item = ReceivableItem::withoutGlobalScope(TenantScope::class)->withTrashed()->firstOrNew([
                    'tenant_id' => $tenantId,
                    'invoice_id' => (string) $invoice->id,
                    'sequence' => $row['sequence'],
                ]);

                if (! $item->exists) {
                    $item->id = (string) Str::uuid7();
                    $item->open_amount = $row['amount'];
                    $item->status = ReceivableItem::StatusOpen;
                }

                $item->fill([
                    'customer_id' => (string) $invoice->contact_id,
                    'document_type' => $type,
                    'document_number' => $invoice->invoice_number,
                    'currency' => (string) ($invoice->currency ?? 'EUR'),
                    'original_amount' => $row['amount'],
                    'issue_date' => Carbon::parse((string) ($invoice->date ?? now()))->toDateString(),
                    'due_date' => $row['due_date'] ?? Carbon::parse((string) ($invoice->date ?? now()))->toDateString(),
                ]);
                $item->deleted_at = null;
                $item->save();

                $this->reconciliation->mirrorPaidTotal($item, $row['paid'], $invoice->updated_at?->toDateString());
                $items[] = $item->refresh();
            }

            // A credit note takes whole installments off the schedule: their items go with them.
            ReceivableItem::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenantId)
                ->where('invoice_id', (string) $invoice->id)
                ->whereNotIn('sequence', array_map(static fn (ReceivableItem $item): int => (int) $item->sequence, $items))
                ->get()
                ->each->delete();

            return $items;
        });
    }

    /**
     * Backfills the invoices of the company (or of one customer) that have no open items yet, and refreshes the ones
     * that do, so the read side never works on a stale mirror.
     */
    public function syncTenant(string $tenantId, ?string $customerId = null): int
    {
        $synced = 0;

        SalesInvoice::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [InvoiceStatus::Approved->value, InvoiceStatus::Posted->value])
            ->whereNotIn('invoice_type', ['proforma', 'credit_note'])
            ->when($customerId !== null, static fn ($query) => $query->where('contact_id', $customerId))
            ->whereNotExists(static fn ($query) => $query->selectRaw('1')->from('receivable_items')->whereColumn('receivable_items.invoice_id', 'invoices.id')->whereNull('receivable_items.deleted_at'))
            ->chunkById(200, function ($invoices) use (&$synced): void {
                foreach ($invoices as $invoice) {
                    $synced += count($this->syncInvoice($invoice));
                }
            });

        return $synced;
    }

    /**
     * Open items of the company, filtered and paginated.
     *
     * @param  array{customer_id?: ?string, status?: ?string, document_type?: ?string, currency?: ?string, due_from?: ?string, due_to?: ?string, issued_from?: ?string, issued_to?: ?string, value_from?: ?string, value_to?: ?string, open_only?: ?bool, bucket?: ?string, as_of?: ?string}  $filters
     * @return LengthAwarePaginator<int, ReceivableItem>
     */
    public function paginate(string $tenantId, array $filters, int $perPage, AgingBucketPolicy $policy, CarbonInterface $today): LengthAwarePaginator
    {
        $query = $this->filtered($tenantId, $filters, $policy, $today)->orderBy('due_date')->orderBy('document_number');

        return $query->paginate(max(1, min(200, $perPage)));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<ReceivableItem>
     */
    public function filtered(string $tenantId, array $filters, AgingBucketPolicy $policy, CarbonInterface $today): Builder
    {
        $query = ReceivableItem::query()->where('tenant_id', $tenantId);

        foreach (['customer_id', 'document_type', 'currency'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        if (! empty($filters['status'])) {
            match ($filters['status']) {
                ReceivableItem::StatusOverdue => $query->whereIn('status', [ReceivableItem::StatusOpen, ReceivableItem::StatusPartiallyPaid])->whereDate('due_date', '<', $today->toDateString()),
                ReceivableItem::StatusOpen, ReceivableItem::StatusPartiallyPaid => $query->where('status', $filters['status'])->whereDate('due_date', '>=', $today->toDateString()),
                default => $query->where('status', $filters['status']),
            };
        }

        foreach ([['due_date', 'due_from', 'due_to'], ['issue_date', 'issued_from', 'issued_to'], ['value_date', 'value_from', 'value_to']] as [$column, $from, $to]) {
            if (! empty($filters[$from])) {
                $query->whereDate($column, '>=', $filters[$from]);
            }

            if (! empty($filters[$to])) {
                $query->whereDate($column, '<=', $filters[$to]);
            }
        }

        if (! empty($filters['open_only'])) {
            $query->where('open_amount', '>', 0)->where('status', '!=', ReceivableItem::StatusWrittenOff);
        }

        if (! empty($filters['bucket'])) {
            $bucket = collect($policy->buckets())->firstWhere('key', $filters['bucket']);

            if ($bucket === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('open_amount', '>', 0)->whereNotIn('status', [ReceivableItem::StatusWrittenOff]);

                if ($bucket['key'] === AgingBucketPolicy::Current) {
                    $query->whereDate('due_date', '>=', $today->toDateString());
                } else {
                    $newest = $today->copy()->subDays($bucket['from'])->toDateString();
                    $query->whereDate('due_date', '<=', $newest);

                    if ($bucket['to'] !== null) {
                        $query->whereDate('due_date', '>=', $today->copy()->subDays($bucket['to'])->toDateString());
                    }
                }
            }
        }

        return $query;
    }

    /**
     * Customer ledger (mastrino): the open items of a customer with their allocations and a running balance of what
     * the customer owes, oldest movement first. Allocations are movements of their own, credited to the customer.
     *
     * @param  array{from?: ?string, to?: ?string, document_type?: ?string, status?: ?string, currency?: ?string}  $filters
     * @return array{movements: list<array<string, mixed>>, closing_balance: string}
     */
    public function ledger(string $tenantId, string $customerId, array $filters = []): array
    {
        $items = ReceivableItem::query()
            ->with(['allocations' => static fn ($query) => $query->whereNull('reversed_at')->orderBy('value_date')])
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->when(! empty($filters['document_type']), static fn ($query) => $query->where('document_type', $filters['document_type']))
            ->when(! empty($filters['currency']), static fn ($query) => $query->where('currency', $filters['currency']))
            ->when(! empty($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
            ->get();

        $movements = [];

        foreach ($items as $item) {
            $movements[] = [
                'date' => $item->issue_date->toDateString(),
                'value_date' => $item->issue_date->toDateString(),
                'kind' => 'document',
                'document_type' => $item->document_type,
                'reference' => $item->document_number,
                'receivable_item_id' => (string) $item->id,
                'allocation_id' => null,
                'currency' => $item->currency,
                'debit' => (string) $item->original_amount,
                'credit' => '0.00',
                'due_date' => $item->due_date->toDateString(),
                'status' => (string) $item->status,
            ];

            /** @var ReceivableAllocation $allocation */
            foreach ($item->allocations as $allocation) {
                $negative = Decimal::isNegative((string) $allocation->amount);
                $movements[] = [
                    'date' => $allocation->value_date->toDateString(),
                    'value_date' => $allocation->value_date->toDateString(),
                    'kind' => $allocation->source_type,
                    'document_type' => $allocation->source_type,
                    'reference' => $allocation->source_reference ?? $item->document_number,
                    'receivable_item_id' => (string) $item->id,
                    'allocation_id' => (string) $allocation->id,
                    'currency' => $item->currency,
                    'debit' => $negative ? Decimal::abs((string) $allocation->amount) : '0.00',
                    'credit' => $negative ? '0.00' : (string) $allocation->amount,
                    'due_date' => null,
                    'status' => (string) $item->status,
                ];
            }
        }

        usort($movements, static fn (array $a, array $b): int => [$a['date'], $a['kind'] === 'document' ? 0 : 1, $a['reference']] <=> [$b['date'], $b['kind'] === 'document' ? 0 : 1, $b['reference']]);

        $running = '0.00';
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $result = [];

        foreach ($movements as $movement) {
            $running = Decimal::round2(Decimal::sub(Decimal::add($running, $movement['debit']), $movement['credit']));

            if (($from !== null && $movement['date'] < $from) || ($to !== null && $movement['date'] > $to)) {
                continue;
            }

            $result[] = $movement + ['balance' => $running];
        }

        return ['movements' => $result, 'closing_balance' => $running];
    }

    private function isReceivable(SalesInvoice $invoice): bool
    {
        return in_array($invoice->status, [InvoiceStatus::Approved->value, InvoiceStatus::Posted->value], true)
            && ! in_array($invoice->invoice_type, ['proforma', 'credit_note'], true)
            && ! TipoDocumento::ofInvoice($invoice)->isSelfInvoice();
    }
}
