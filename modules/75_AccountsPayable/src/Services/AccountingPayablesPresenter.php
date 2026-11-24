<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Services;

use App\Models\Contact;
use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\AccountsPayable\Models\ApOpenItem;
use Modules\AccountsPayable\Models\ApPaymentRun;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * The shapes of `/api/v1/accounting/payables` (the contract of the web client: items, batches, aging) drawn from the open
 * items, payment runs and aging report of the module. The client's names differ from the domain's (`in_batch` for `in_proposal`,
 * `pending_approval` for `pending_cfo_approval`, `xml_invoice` for an electronic invoice, `batch` for payment run): this class is
 * the only place that translates them.
 *
 * @phpstan-import-type Plan from PaymentRunPlanner
 * @phpstan-import-type AgingReport from PayablesAgingService
 */
final class AccountingPayablesPresenter
{
    private const array ItemStatuses = ['in_proposal' => 'in_batch'];

    private const array BatchStatuses = ['pending_cfo_approval' => 'pending_approval', 'authorized' => 'approved'];

    /**
     * @return array<string, mixed>
     */
    public function item(ApOpenItem $item, bool $withHistory = false): array
    {
        $supplier = $item->relationLoaded('supplier') ? $item->supplier : null;
        $row = [
            'id' => (string) $item->id,
            'supplier_id' => (string) $item->supplier_id,
            'supplier_name' => $this->supplierName($supplier),
            'supplier_iban' => $item->creditor_iban,
            'document_type' => $this->documentType($item),
            'document_number' => (string) $item->document_number,
            'issue_date' => $item->document_date->toDateString(),
            'due_date' => $item->due_date->toDateString(),
            'currency' => (string) $item->currency,
            'original_amount' => (string) $item->original_amount,
            'open_amount' => (string) $item->open_amount,
            'status' => self::ItemStatuses[$item->status] ?? (string) $item->status,
            'block_reason' => $item->hold_reason,
            'batch_id' => $item->getAttribute('payment_run_id'),
            'skonto' => $this->skonto($item),
        ];

        if ($withHistory) {
            $events = $item->events()->whereIn('event', ['disputed', 'blocked', 'released'])->get();
            $names = User::query()->whereIn('id', $events->pluck('actor_id')->filter()->all())->pluck('name', 'id');
            $row['block_history'] = $events->map(static fn ($event): array => [
                'id' => (string) $event->id,
                'kind' => match ($event->event) {
                    'disputed' => 'dispute',
                    'blocked' => 'block',
                    default => 'release',
                },
                'reason' => (string) $event->reason,
                'user_name' => $event->actor_id !== null ? $names->get($event->actor_id) : null,
                'created_at' => $event->occurred_at->toIso8601String(),
            ])->values()->all();
        }

        return $row;
    }

    /**
     * A saved payment run as the client's batch.
     *
     * @return array<string, mixed>
     */
    public function batch(ApPaymentRun $run): array
    {
        $run->loadMissing(['items', 'transfers', 'offsets']);
        $items = ApOpenItem::query()->where('tenant_id', $run->tenant_id)->whereIn('id', $run->items->pluck('open_item_id'))->get()->keyBy('id');
        $rows = $run->items->map(static function ($row) use ($items): array {
            /** @var ApOpenItem|null $item */
            $item = $items->get($row->open_item_id);

            return [
                'open_item_id' => (string) $row->open_item_id,
                'supplier_id' => (string) $row->supplier_id,
                'kind' => (string) $row->kind,
                'document_number' => (string) $item?->document_number,
                'due_date' => $item?->due_date->toDateString() ?? '',
                'selection_reason' => (string) $row->selection_reason,
                'open_amount' => (string) $row->open_amount,
                'skonto_percentage' => $row->skonto_percentage === null ? null : (string) $row->skonto_percentage,
                'skonto_deadline' => $row->skonto_deadline?->toDateString(),
                'skonto_amount' => (string) $row->skonto_amount,
                'credit_applied_amount' => (string) $row->credit_applied_amount,
                'cash_amount' => (string) $row->cash_amount,
                'credit_used_amount' => (string) $row->credit_used_amount,
                'creditor_iban' => $item?->creditor_iban,
                'transfer_id' => $row->transfer_id,
            ];
        })->all();
        $transfers = $run->transfers->map(static fn ($transfer): array => [
            'id' => (string) $transfer->id,
            'supplier_id' => (string) $transfer->supplier_id,
            'creditor_iban' => $transfer->creditor_iban,
            'currency' => (string) $transfer->currency,
            'gross_amount' => (string) $transfer->gross_amount,
            'skonto_amount' => (string) $transfer->skonto_amount,
            'credit_netted_amount' => (string) $transfer->credit_netted_amount,
            'amount' => (string) $transfer->amount,
            'item_ids' => collect($rows)->where('transfer_id', (string) $transfer->id)->pluck('open_item_id')->values()->all(),
        ])->all();
        $offsets = $run->offsets->map(static fn ($offset): array => ['debit_item_id' => (string) $offset->debit_item_id, 'credit_item_id' => (string) $offset->credit_item_id, 'amount' => (string) $offset->amount])->all();
        $approval = match (true) {
            $run->approved_by !== null => ['user' => $run->approved_by, 'decision' => 'approved', 'reason' => null, 'decided_at' => $run->approved_at?->toIso8601String()],
            $run->rejected_by !== null => ['user' => $run->rejected_by, 'decision' => 'rejected', 'reason' => $run->rejection_reason, 'decided_at' => $run->rejected_at?->toIso8601String()],
            default => null,
        };

        return $this->assemble(
            id: (string) $run->id,
            number: (string) $run->run_number,
            status: self::BatchStatuses[$run->status] ?? (string) $run->status,
            cutoffDate: $run->cutoff_date->toDateString(),
            currency: (string) $run->currency,
            prioritizeSkonto: (bool) $run->include_skonto,
            rows: $rows,
            transfers: $transfers,
            offsets: $offsets,
            totals: [
                'debit_total' => (string) $run->debit_total, 'credit_netted_total' => (string) $run->credit_netted_total, 'skonto_total' => (string) $run->skonto_total,
                'net_total' => (string) $run->net_total, 'transfers_count' => (int) $run->transfers_count,
            ],
            approval: $approval,
            executedAt: $run->executed_at?->toIso8601String(),
            createdAt: $run->created_at?->toIso8601String() ?? now()->toIso8601String(),
        );
    }

    /**
     * The plan of a run that is not saved, as the client's batch (a preview).
     *
     * @param  Plan  $plan
     * @return array<string, mixed>
     */
    public function plannedBatch(array $plan, string $cutoffDate, string $currency, bool $prioritizeSkonto): array
    {
        $rows = array_map(static fn (array $row): array => $row + ['transfer_id' => null], $plan['items']);
        $transfers = array_map(static fn (array $transfer): array => $transfer, $plan['transfers']);

        return $this->assemble('preview', null, 'draft', $cutoffDate, $currency, $prioritizeSkonto, $rows, $transfers, $plan['offsets'], $plan['totals'], null, null, now()->toIso8601String());
    }

    /**
     * The open items of the payables aging in the client's shape.
     *
     * @param  AgingReport  $report
     * @return array<string, mixed>
     */
    public function aging(array $report): array
    {
        return [
            'as_of' => $report['as_of'],
            'currency' => $report['currency'],
            'available_currencies' => $report['available_currencies'],
            'buckets' => $report['buckets'],
            'totals' => $report['summary']['buckets'],
            'total_open' => $report['summary']['total_open'],
            'total_overdue' => $report['summary']['total_overdue'],
            'suppliers' => array_map(static fn (array $supplier): array => [
                'supplier_id' => $supplier['supplier_id'],
                'supplier_name' => (string) $supplier['supplier_name'],
                'buckets' => $supplier['buckets'],
                'total_open' => $supplier['total_open'],
                'total_overdue' => $supplier['total_overdue'],
                'items' => $supplier['items_count'],
            ], $report['suppliers']),
        ];
    }

    /**
     * The reason the client shows for an item left out of a proposal.
     */
    public function excludedReason(string $reason): string
    {
        return match ($reason) {
            'disputed' => 'disputed',
            'in_treasury_proposal', 'in_batch' => 'in_batch',
            default => 'blocked',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $transfers
     * @param  list<array{debit_item_id: string, credit_item_id: string, amount: string}>  $offsets
     * @param  array<string, mixed>  $totals
     * @param  array{user: string, decision: string, reason: string|null, decided_at: string|null}|null  $approval
     * @return array<string, mixed>
     */
    private function assemble(string $id, ?string $number, string $status, string $cutoffDate, string $currency, bool $prioritizeSkonto, array $rows, array $transfers, array $offsets, array $totals, ?array $approval, ?string $executedAt, string $createdAt): array
    {
        $names = Contact::query()->whereIn('id', array_values(array_unique(array_column($rows, 'supplier_id'))))->get()->mapWithKeys(fn (Contact $contact): array => [(string) $contact->id => $this->supplierName($contact)]);
        $invoices = array_values(array_filter($rows, static fn (array $row): bool => $row['kind'] === 'invoice'));
        $groups = [];

        foreach ($transfers as $transfer) {
            $lines = [];

            foreach ($invoices as $row) {
                if (in_array($row['open_item_id'], $transfer['item_ids'], true)) {
                    $lines[] = [
                        'item_id' => $row['open_item_id'],
                        'supplier_id' => $row['supplier_id'],
                        'supplier_name' => $names->get($row['supplier_id']),
                        'document_number' => $row['document_number'],
                        'due_date' => $row['due_date'],
                        'open_amount' => $row['open_amount'],
                        'pay_amount' => Decimal::round2(Decimal::sub($row['open_amount'], $row['skonto_amount'])),
                        'skonto_taken' => Decimal::isPositive($row['skonto_amount']),
                        'skonto_savings' => $row['skonto_amount'],
                    ];
                }
            }

            $groups[] = [
                'iban' => (string) $transfer['creditor_iban'],
                'supplier_id' => $transfer['supplier_id'],
                'supplier_name' => $names->get($transfer['supplier_id']),
                'currency' => $transfer['currency'],
                'gross_amount' => $transfer['gross_amount'],
                'set_off_amount' => $transfer['credit_netted_amount'],
                'skonto_savings' => $transfer['skonto_amount'],
                'net_amount' => $transfer['amount'],
                'lines' => $lines,
            ];
        }

        $setOffs = [];

        foreach ($rows as $row) {
            if ($row['kind'] === 'credit_note') {
                $setOffs[] = [
                    'credit_note_id' => $row['open_item_id'],
                    'credit_note_number' => $row['document_number'],
                    'supplier_id' => $row['supplier_id'],
                    'supplier_name' => $names->get($row['supplier_id']),
                    'amount' => $row['credit_used_amount'],
                    'applied_to_item_ids' => array_values(array_map(static fn (array $offset): string => $offset['debit_item_id'], array_filter($offsets, static fn (array $offset): bool => $offset['credit_item_id'] === $row['open_item_id']))),
                ];
            }
        }

        if ($approval !== null) {
            $approval = ['user_name' => User::query()->whereKey($approval['user'])->value('name')] + array_diff_key($approval, ['user' => true]);
        }

        return [
            'id' => $id,
            'number' => $number,
            'status' => $status,
            'cutoff_date' => $cutoffDate,
            'currency' => $currency,
            'prioritize_skonto' => $prioritizeSkonto,
            'totals' => [
                'gross_amount' => $totals['debit_total'],
                'set_off_amount' => $totals['credit_netted_total'],
                'skonto_savings' => $totals['skonto_total'],
                'net_amount' => $totals['net_total'],
                'transfers' => (int) $totals['transfers_count'],
                'lines' => count($invoices),
            ],
            'transfers' => $groups,
            'set_offs' => $setOffs,
            'approval' => $approval,
            'executed_at' => $executedAt,
            'created_at' => $createdAt,
        ];
    }

    private function documentType(ApOpenItem $item): string
    {
        return match (true) {
            $item->isCreditNote() => 'credit_note',
            $item->source === 'swiss_qr' => 'qr_bill',
            $item->source === 'manual' => 'manual_bill',
            default => 'xml_invoice',
        };
    }

    /**
     * The terms and the opportunity of an invoice. An invoice whose deadline passed still shows them (the savings it missed), so
     * the client can tell an expired discount from none; a credit note or an item without terms has none.
     *
     * @return array<string, mixed>|null
     */
    private function skonto(ApOpenItem $item): ?array
    {
        /** @var array<string, mixed> $figures */
        $figures = (array) $item->getAttribute('skonto');

        if ($item->isCreditNote() || ($figures['skonto_percentage'] ?? null) === null) {
            return null;
        }

        $percentage = (string) $figures['skonto_percentage'];
        $savings = Decimal::round2(Decimal::div(Decimal::mul((string) $item->open_amount, $percentage), '100'));
        $deadline = CarbonImmutable::parse((string) $figures['skonto_deadline_date']);
        $days = (int) $deadline->diffInDays($item->document_date->toImmutable()->startOfDay(), true);
        $terms = ($figures['skonto_net_days'] ?? null) !== null ? sprintf('%d days, %s%% within %d days', $figures['skonto_net_days'], $this->trim($percentage), $days) : sprintf('%s%% within %d days', $this->trim($percentage), $days);

        return [
            'percent' => $percentage,
            'deadline' => $deadline->toDateString(),
            'days_remaining' => (int) $figures['skonto_days_remaining'],
            'savings' => $savings,
            'net_payable' => Decimal::round2(Decimal::sub((string) $item->open_amount, $savings)),
            'terms' => $terms,
        ];
    }

    private function trim(string $percentage): string
    {
        return rtrim(rtrim($percentage, '0'), '.');
    }

    private function supplierName(?Contact $supplier): string
    {
        return $supplier === null ? '' : (string) ($supplier->company_name ?: trim($supplier->first_name.' '.$supplier->last_name));
    }
}
