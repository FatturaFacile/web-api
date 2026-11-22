<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services\Receivables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Models\CustomerLedgerEntry;
use Modules\Invoicing\Models\InvoiceCreditApplication;
use Modules\Invoicing\Models\ReceivableAllocation;
use Modules\Invoicing\Models\ReceivableItem;
use Modules\Invoicing\Models\SalesInvoice;

/**
 * Partial and full reconciliation of the open items: a payment, a credit note, a write-off or a discount settles part
 * or all of an item, a debit adjustment raises it. Every movement is an allocation; the open amount and the status of
 * the item are recomputed from the allocations that were not reversed.
 */
final class ReceivableReconciliationService
{
    /**
     * Settles (or, for a debit adjustment, raises) one open item.
     *
     * @throws ValidationException when the amount, the item or the source do not allow the movement
     */
    public function allocate(
        string $itemId,
        string $type,
        string $amount,
        string $valueDate,
        ?string $sourceId,
        ?string $reference,
        ?string $notes,
        ?string $userId,
    ): ReceivableAllocation {
        return DB::transaction(function () use ($itemId, $type, $amount, $valueDate, $sourceId, $reference, $notes, $userId): ReceivableAllocation {
            $item = ReceivableItem::query()->whereKey($itemId)->lockForUpdate()->firstOrFail();
            $amount = Decimal::round2($amount);

            if (! in_array($type, ReceivableAllocation::Types, true) || ! Decimal::isPositive($amount)) {
                throw ValidationException::withMessages(['amount' => __('receivables.allocation_amount_positive')]);
            }

            if ($type !== ReceivableAllocation::TypeDebitAdjustment) {
                if ($item->status === ReceivableItem::StatusWrittenOff || ! Decimal::isPositive((string) $item->open_amount)) {
                    throw ValidationException::withMessages(['receivable_item_id' => __('receivables.item_not_open')]);
                }

                if (Decimal::compare($amount, (string) $item->open_amount) > 0) {
                    throw ValidationException::withMessages(['amount' => __('receivables.allocation_exceeds_open')]);
                }
            }

            if ($type === ReceivableAllocation::TypeCreditNote) {
                $this->assertCreditNoteCovers($item, (string) $sourceId, $amount);
            } elseif ($type === ReceivableAllocation::TypePayment && $sourceId !== null) {
                $this->assertPaymentCovers($item, $sourceId, $amount);
            }

            $allocation = ReceivableAllocation::create([
                'tenant_id' => $item->tenant_id,
                'receivable_item_id' => (string) $item->id,
                'source_type' => $type,
                'origin' => ReceivableAllocation::OriginManual,
                'source_id' => $sourceId,
                'source_reference' => $reference,
                'amount' => $type === ReceivableAllocation::TypeDebitAdjustment ? Decimal::neg($amount) : $amount,
                'value_date' => $valueDate,
                'notes' => $notes,
                'allocated_by' => $userId !== null && Str::isUuid($userId) ? $userId : null,
            ]);

            $this->recompute($item);

            return $allocation;
        });
    }

    /**
     * Several allocations in one transaction: all of them are made, or none.
     *
     * @param  list<array{receivable_item_id: string, type: string, amount: string|int|float, value_date: string, source_id?: ?string, reference?: ?string, notes?: ?string}>  $rows
     * @return list<ReceivableAllocation>
     */
    public function allocateMany(array $rows, ?string $userId): array
    {
        return DB::transaction(function () use ($rows, $userId): array {
            $created = [];

            foreach ($rows as $row) {
                $created[] = $this->allocate(
                    (string) $row['receivable_item_id'],
                    (string) $row['type'],
                    (string) $row['amount'],
                    (string) $row['value_date'],
                    $row['source_id'] ?? null,
                    $row['reference'] ?? null,
                    $row['notes'] ?? null,
                    $userId,
                );
            }

            return $created;
        });
    }

    /**
     * Cancels an allocation made by mistake. It stays on record, reversed, and no longer counts.
     *
     * @throws ValidationException when it is already reversed or mirrors the paid total of an invoice
     */
    public function reverse(string $allocationId, ?string $userId): ReceivableAllocation
    {
        return DB::transaction(function () use ($allocationId, $userId): ReceivableAllocation {
            $allocation = ReceivableAllocation::query()->whereKey($allocationId)->lockForUpdate()->firstOrFail();

            if ($allocation->reversed_at !== null) {
                throw ValidationException::withMessages(['allocation' => __('receivables.allocation_already_reversed')]);
            }

            if ($allocation->origin === ReceivableAllocation::OriginSync) {
                throw ValidationException::withMessages(['allocation' => __('receivables.allocation_mirrors_invoice')]);
            }

            $item = ReceivableItem::query()->whereKey($allocation->receivable_item_id)->lockForUpdate()->firstOrFail();

            // Reversing a debit adjustment must not leave the item with more settled than owed.
            if (Decimal::isNegative((string) $allocation->amount)) {
                $after = Decimal::sub((string) $item->open_amount, Decimal::abs((string) $allocation->amount));

                if (Decimal::isNegative($after)) {
                    throw ValidationException::withMessages(['allocation' => __('receivables.allocation_exceeds_open')]);
                }
            }

            $allocation->update(['reversed_at' => now(), 'reversed_by' => $userId !== null && Str::isUuid($userId) ? $userId : null]);
            $this->recompute($item);

            return $allocation->refresh();
        });
    }

    /**
     * Mirrors the paid total of the invoice on one of its items as `sync` allocations, so a payment recorded by the
     * invoicing flow, the bank reconciliation or a credit note applied there settles the item as well.
     */
    public function mirrorPaidTotal(ReceivableItem $item, string $paid, ?string $valueDate): void
    {
        $synced = $this->sum(ReceivableAllocation::query()->where('receivable_item_id', $item->id)->where('origin', ReceivableAllocation::OriginSync));

        if (Decimal::compare($paid, $synced) === 0) {
            $this->recompute($item);

            return;
        }

        if (Decimal::compare($paid, $synced) < 0) {
            ReceivableAllocation::query()->where('receivable_item_id', $item->id)->where('origin', ReceivableAllocation::OriginSync)->whereNull('reversed_at')
                ->update(['reversed_at' => now()]);
            $synced = '0.00';
        }

        $delta = Decimal::sub($paid, $synced);
        $manual = $this->sum(ReceivableAllocation::query()->where('receivable_item_id', $item->id)->where('origin', ReceivableAllocation::OriginManual));
        $room = Decimal::sub((string) $item->original_amount, Decimal::add($synced, $manual));
        $delta = Decimal::compare($delta, $room) > 0 ? $room : $delta;

        if (Decimal::isPositive($delta)) {
            ReceivableAllocation::create([
                'tenant_id' => $item->tenant_id,
                'receivable_item_id' => (string) $item->id,
                'source_type' => ReceivableAllocation::TypePayment,
                'origin' => ReceivableAllocation::OriginSync,
                'source_id' => $item->invoice_id,
                'source_reference' => $item->document_number,
                'amount' => Decimal::round2($delta),
                'value_date' => $valueDate ?? now()->toDateString(),
            ]);
        }

        $this->recompute($item);
    }

    /**
     * Marks an item as disputed (excluded from the overdue reminders, still counted in the exposure) or clears it.
     */
    public function setDisputed(string $itemId, bool $disputed, ?string $reason): ReceivableItem
    {
        return DB::transaction(function () use ($itemId, $disputed, $reason): ReceivableItem {
            $item = ReceivableItem::query()->whereKey($itemId)->lockForUpdate()->firstOrFail();

            if ($disputed && ! $item->isOpen()) {
                throw ValidationException::withMessages(['receivable_item_id' => __('receivables.item_not_open')]);
            }

            $item->forceFill([
                'dispute_reason' => $disputed ? $reason : null,
                'disputed_at' => $disputed ? now() : null,
            ])->save();

            return $this->recompute($item);
        });
    }

    /**
     * Recomputes the open amount, the value date and the status of an item from its live allocations.
     */
    public function recompute(ReceivableItem $item): ReceivableItem
    {
        $live = ReceivableAllocation::query()->where('receivable_item_id', $item->id)->whereNull('reversed_at');
        $settled = $this->sum(clone $live);
        $open = Decimal::round2(Decimal::sub((string) $item->original_amount, $settled));
        $open = Decimal::isNegative($open) ? '0.00' : $open;
        $writtenOff = $this->sum((clone $live)->where('source_type', ReceivableAllocation::TypeWriteOff));
        $paidLike = $this->sum((clone $live)->whereIn('source_type', [ReceivableAllocation::TypePayment, ReceivableAllocation::TypeCreditNote, ReceivableAllocation::TypeDiscount]));

        $status = match (true) {
            ! Decimal::isPositive($open) && Decimal::isPositive($writtenOff) && Decimal::compare($writtenOff, $paidLike) >= 0 => ReceivableItem::StatusWrittenOff,
            ! Decimal::isPositive($open) => ReceivableItem::StatusPaid,
            $item->disputed_at !== null => ReceivableItem::StatusDisputed,
            Decimal::compare($open, (string) $item->original_amount) < 0 => ReceivableItem::StatusPartiallyPaid,
            default => ReceivableItem::StatusOpen,
        };

        $latest = (clone $live)->orderByDesc('value_date')->value('value_date');

        $item->forceFill([
            'open_amount' => $open,
            'status' => $status,
            'value_date' => $latest !== null ? substr((string) $latest, 0, 10) : null,
        ])->save();

        return $item;
    }

    /**
     * Payments received without an invoice (on account) that are not allocated to open items yet.
     *
     * @return list<array{ledger_entry_id: string, reference: ?string, date: string, currency: string, amount: string, unallocated: string}>
     */
    public function unreconciledPayments(string $tenantId, string $customerId): array
    {
        $entries = CustomerLedgerEntry::query()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->where('entry_type', 'payment')
            ->whereNull('invoice_id')
            ->get();

        $result = [];

        foreach ($entries as $entry) {
            $allocated = $this->sum(ReceivableAllocation::query()->where('tenant_id', $tenantId)->where('source_type', ReceivableAllocation::TypePayment)->where('source_id', $entry->id)->whereNull('reversed_at'));
            $left = Decimal::round2(Decimal::sub((string) $entry->credit_amount, $allocated));

            if (Decimal::isPositive($left)) {
                $result[] = [
                    'ledger_entry_id' => (string) $entry->id,
                    'reference' => $entry->document_reference,
                    'date' => substr((string) $entry->getRawOriginal('entry_date'), 0, 10),
                    'currency' => (string) $entry->currency,
                    'amount' => Decimal::round2((string) $entry->credit_amount),
                    'unallocated' => $left,
                ];
            }
        }

        return $result;
    }

    private function assertPaymentCovers(ReceivableItem $item, string $entryId, string $amount): void
    {
        $left = collect($this->unreconciledPayments((string) $item->tenant_id, (string) $item->customer_id))->firstWhere('ledger_entry_id', $entryId);

        if ($left === null) {
            throw ValidationException::withMessages(['source_id' => __('receivables.payment_not_available')]);
        }

        if (Decimal::compare($amount, $left['unallocated']) > 0) {
            throw ValidationException::withMessages(['amount' => __('receivables.payment_exceeds_available')]);
        }
    }

    private function assertCreditNoteCovers(ReceivableItem $item, string $creditNoteId, string $amount): void
    {
        $creditNote = Str::isUuid($creditNoteId)
            ? SalesInvoice::query()->where('tenant_id', $item->tenant_id)->where('invoice_type', 'credit_note')->whereKey($creditNoteId)->first()
            : null;

        if ($creditNote === null || (string) $creditNote->contact_id !== (string) $item->customer_id) {
            throw ValidationException::withMessages(['source_id' => __('receivables.credit_note_invalid')]);
        }

        $applied = (string) (InvoiceCreditApplication::query()->where('credit_note_id', $creditNote->id)->sum('applied_amount') ?: 0);
        $allocated = $this->sum(ReceivableAllocation::query()->where('tenant_id', $item->tenant_id)->where('source_type', ReceivableAllocation::TypeCreditNote)->where('source_id', $creditNote->id)->whereNull('reversed_at'));
        $available = Decimal::sub(Decimal::sub((string) $creditNote->total_gross, $applied), $allocated);

        if (Decimal::compare($amount, $available) > 0) {
            throw ValidationException::withMessages(['amount' => __('receivables.credit_note_exceeds_available')]);
        }
    }

    /**
     * @param  Builder<ReceivableAllocation>  $query
     */
    private function sum(Builder $query): string
    {
        return Decimal::round2((string) ($query->whereNull('reversed_at')->sum('amount') ?: '0'));
    }
}
