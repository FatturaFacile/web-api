<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Services;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\AccountsPayable\Enums\OpenItemKind;
use Modules\AccountsPayable\Enums\OpenItemSource;
use Modules\AccountsPayable\Enums\OpenItemStatus;
use Modules\AccountsPayable\Exceptions\AccountsPayableException;
use Modules\AccountsPayable\Models\ApOpenItem;
use Modules\AccountsPayable\Models\ApOpenItemEvent;
use Modules\AccountsPayable\Models\ApPaymentRun;
use Modules\AccountsPayable\Models\ApPaymentRunItem;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * The supplier ledger (partitario fornitori): listing and detail of the open items with their cash-discount figures,
 * manual entries, the dispute and administrative holds that stop a payment, and the audit history of every change.
 */
final class OpenItemService
{
    public function __construct(
        private readonly OpenItemSyncService $sync,
        private readonly SkontoTermsResolver $terms,
        private readonly SkontoCalculator $skonto,
    ) {}

    /**
     * @param  array{supplier_id?: string|null, status?: string|null, kind?: string|null, source?: string|null, currency?: string|null, due_from?: string|null, due_to?: string|null, search?: string|null, only_payable?: bool|null, with_balance?: bool|null, skonto_eligible?: bool|null}  $filters
     */
    public function paginate(string $tenantId, array $filters, CarbonInterface $asOf, int $perPage, ?int $page = null): LengthAwarePaginator
    {
        $page ??= Paginator::resolveCurrentPage();
        $this->sync->syncTenant($tenantId, $filters['supplier_id'] ?? null);

        $query = ApOpenItem::query()
            ->with(['supplier', 'passiveInvoice'])
            ->where('tenant_id', $tenantId)
            ->when($filters['supplier_id'] ?? null, static fn ($q, $value) => $q->where('supplier_id', $value))
            ->when($filters['status'] ?? null, static fn ($q, $value) => $q->where('status', $value))
            ->when($filters['kind'] ?? null, static fn ($q, $value) => $q->where('kind', $value))
            ->when($filters['source'] ?? null, static fn ($q, $value) => $q->where('source', $value))
            ->when($filters['currency'] ?? null, static fn ($q, $value) => $q->where('currency', $value))
            ->when($filters['due_from'] ?? null, static fn ($q, $value) => $q->whereDate('due_date', '>=', $value))
            ->when($filters['due_to'] ?? null, static fn ($q, $value) => $q->whereDate('due_date', '<=', $value))
            ->when($filters['search'] ?? null, static fn ($q, $value) => $q->whereRaw('LOWER(document_number) LIKE ?', ['%'.mb_strtolower((string) $value).'%']))
            ->when($filters['only_payable'] ?? false, static fn ($q) => $q->whereIn('status', [OpenItemStatus::Open->value])->where('open_amount', '>', 0))
            ->when($filters['with_balance'] ?? false, static fn ($q) => $q->where('open_amount', '>', 0))
            ->orderBy('due_date')
            ->orderBy('document_number');

        // The discount depends on the date and on the terms, so it is tested on the items, not in the query: the filtered list is paged by hand.
        if (($filters['skonto_eligible'] ?? null) === true) {
            $items = $query->get();
            $this->decorate($items, $asOf);
            $eligible = $items->filter(static fn (ApOpenItem $item): bool => (bool) ($item->getAttribute('skonto')['skonto_eligible'] ?? false))->values();

            return new Paginator($eligible->forPage($page, $perPage)->values(), $eligible->count(), $perPage, $page, ['path' => Paginator::resolveCurrentPath()]);
        }

        $paged = $query->paginate($perPage, ['*'], 'page', $page);
        $this->decorate($paged->getCollection(), $asOf);

        return $paged;
    }

    /**
     * The totals of the supplier ledger in one currency: what is open on the invoices, what is overdue, the discount that can still
     * be earned and how many items are held.
     *
     * @return array{total_open: string, total_overdue: string, skonto_savings: string, blocked_count: int, currency: string}
     */
    public function summary(string $tenantId, ?string $currency, CarbonInterface $asOf): array
    {
        $this->sync->syncTenant($tenantId);
        $currency = strtoupper($currency ?: (string) (Tenant::query()->whereKey($tenantId)->value('default_currency') ?: 'EUR'));
        $items = ApOpenItem::query()->with(['supplier', 'passiveInvoice'])->where('tenant_id', $tenantId)->where('currency', $currency)->where('open_amount', '>', 0)->get();
        $this->decorate($items, $asOf);
        $invoices = $items->reject(static fn (ApOpenItem $item): bool => $item->isCreditNote());
        $sum = static fn (Collection $rows, callable $amount): string => $rows->reduce(static fn (string $total, ApOpenItem $item): string => Decimal::round2(Decimal::add($total, $amount($item))), '0.00');

        return [
            'total_open' => $sum($invoices, static fn (ApOpenItem $item): string => (string) $item->open_amount),
            'total_overdue' => $sum($invoices->filter(static fn (ApOpenItem $item): bool => $item->daysPastDue($asOf) > 0), static fn (ApOpenItem $item): string => (string) $item->open_amount),
            'skonto_savings' => $sum($invoices, static fn (ApOpenItem $item): string => (string) $item->getAttribute('skonto')['skonto_savings_amount']),
            'blocked_count' => $items->filter(static fn (ApOpenItem $item): bool => $item->isHeld())->count(),
            'currency' => $currency,
        ];
    }

    /**
     * @throws AccountsPayableException when the item does not exist for the tenant
     */
    public function find(string $tenantId, string $id, ?CarbonInterface $asOf = null): ApOpenItem
    {
        $item = ApOpenItem::query()->with(['supplier', 'passiveInvoice'])->where('tenant_id', $tenantId)->whereKey($id)->first()
            ?? throw AccountsPayableException::notFound('open_item_not_found');

        if ($item->passive_invoice_id !== null && $item->passiveInvoice !== null) {
            $item = $this->sync->syncInvoice($item->passiveInvoice->load('supplier'))->load('supplier');
        }

        $this->decorate(collect([$item]), $asOf ?? CarbonImmutable::today());

        return $item;
    }

    /**
     * The history of an item, oldest first.
     *
     * @return Collection<int, ApOpenItemEvent>
     */
    public function history(string $tenantId, string $id): Collection
    {
        $item = $this->find($tenantId, $id);

        return $item->events()->get();
    }

    /**
     * Adds the cash-discount figures of each item as the `skonto` attribute (see SkontoResult::toArray), and the reason
     * it cannot be paid, if any, as `payment_block`.
     *
     * @param  Collection<int, ApOpenItem>  $items
     */
    public function decorate(Collection $items, CarbonInterface $asOf): void
    {
        $terms = $this->terms->forItems($items);
        $runs = ApPaymentRunItem::query()
            ->whereIn('open_item_id', $items->pluck('id'))
            ->whereIn('payment_run_id', ApPaymentRun::query()->whereIn('status', ['draft', 'pending_cfo_approval', 'authorized'])->select('id'))
            ->pluck('payment_run_id', 'open_item_id');

        foreach ($items as $item) {
            $item->setAttribute('payment_run_id', $runs->get($item->id));
            $result = $this->skonto->calculate(
                $terms[(string) $item->id] ?? null,
                $item->document_date,
                (string) $item->open_amount,
                $item->isCreditNote(),
                $item->isPartiallyPaid(),
                $asOf,
            );
            $item->setAttribute('skonto', $result->toArray());
            $item->setAttribute('payment_block', $this->paymentBlock($item));
        }
    }

    /**
     * Why an item cannot be put in a payment run right now, null when it can.
     */
    public function paymentBlock(ApOpenItem $item): ?string
    {
        return match (true) {
            $item->status === OpenItemStatus::Disputed->value => 'disputed',
            $item->status === OpenItemStatus::Blocked->value => 'administrative_block',
            $item->status === OpenItemStatus::Paid->value || ! $item->hasOpenBalance() => 'settled',
            $item->status !== OpenItemStatus::Open->value => 'in_payment_run',
            $item->passive_invoice_id !== null && $item->passiveInvoice?->isPaymentBlocked() === true => 'invoice_not_approved',
            default => null,
        };
    }

    /**
     * A document that is not an electronic or Swiss QR supplier invoice: a bank statement, a manual accrual. It is not
     * posted in the ledger by this module.
     *
     * @param  array{supplier_id: string, kind?: string|null, document_number: string, document_date: string, due_date: string, currency: string, amount: string|float|int, creditor_iban?: string|null, notes?: string|null, skonto_percentage?: string|float|int|null, skonto_days?: int|null}  $data
     */
    public function createManual(string $tenantId, array $data, ?string $actorId): ApOpenItem
    {
        return DB::transaction(function () use ($tenantId, $data, $actorId): ApOpenItem {
            $amount = Decimal::round2((string) $data['amount']);

            $item = ApOpenItem::query()->create([
                'tenant_id' => $tenantId,
                'supplier_id' => $data['supplier_id'],
                'source' => OpenItemSource::Manual->value,
                'kind' => $data['kind'] ?? OpenItemKind::Invoice->value,
                'document_number' => $data['document_number'],
                'document_date' => $data['document_date'],
                'due_date' => $data['due_date'],
                'currency' => strtoupper($data['currency']),
                'original_amount' => $amount,
                'paid_amount' => '0.00',
                'discount_taken_amount' => '0.00',
                'open_amount' => $amount,
                'creditor_iban' => isset($data['creditor_iban']) ? strtoupper(str_replace(' ', '', (string) $data['creditor_iban'])) : null,
                'status' => OpenItemStatus::Open->value,
                'skonto_percentage' => $data['skonto_percentage'] ?? null,
                'skonto_days' => $data['skonto_days'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->record($item, 'created', null, OpenItemStatus::Open, null, $actorId, ['source' => OpenItemSource::Manual->value]);

            return $item;
        });
    }

    /**
     * Sets or clears the cash-discount terms of one document, overriding the supplier terms.
     */
    public function setSkontoTerms(string $tenantId, string $id, ?string $percentage, ?int $days, ?string $actorId): ApOpenItem
    {
        return DB::transaction(function () use ($tenantId, $id, $percentage, $days, $actorId): ApOpenItem {
            $item = $this->lock($tenantId, $id);
            $item->update(['skonto_percentage' => $percentage, 'skonto_days' => $days]);
            $this->record($item, 'skonto_terms_changed', $item->statusEnum(), $item->statusEnum(), null, $actorId, ['skonto_percentage' => $percentage, 'skonto_days' => $days]);

            return $this->find($tenantId, $id);
        });
    }

    /**
     * Puts an item in dispute or under administrative block: it leaves every payment proposal until it is released.
     *
     * @throws AccountsPayableException when the item is settled or already held
     */
    public function hold(string $tenantId, string $id, OpenItemStatus $hold, string $reason, ?string $actorId): ApOpenItem
    {
        if (! $hold->isHold()) {
            throw AccountsPayableException::invalid('hold_type_invalid');
        }

        // The balance may have changed outside the module (a bank reconciliation): refresh it before testing it.
        $this->find($tenantId, $id);

        return DB::transaction(function () use ($tenantId, $id, $hold, $reason, $actorId): ApOpenItem {
            $item = $this->lock($tenantId, $id);

            if ($item->isHeld()) {
                throw AccountsPayableException::conflict('open_item_already_held', ['status' => $item->status]);
            }

            if ($item->statusEnum() === OpenItemStatus::Paid || ! $item->hasOpenBalance()) {
                throw AccountsPayableException::conflict('open_item_settled');
            }

            $from = $item->statusEnum();
            $item->update([
                'status' => $hold->value,
                'status_before_hold' => $from->value,
                'hold_reason' => $reason,
                'held_at' => now(),
                'held_by' => $actorId,
            ]);
            $this->record($item, $hold === OpenItemStatus::Disputed ? 'disputed' : 'blocked', $from, $hold, $reason, $actorId);

            return $this->find($tenantId, $id);
        });
    }

    /**
     * Lifts a dispute or a block with an audited reason. The item goes back to the proposal it was in, or to open.
     *
     * @throws AccountsPayableException when the item is not held
     */
    public function release(string $tenantId, string $id, string $reason, ?string $actorId): ApOpenItem
    {
        return DB::transaction(function () use ($tenantId, $id, $reason, $actorId): ApOpenItem {
            $item = $this->lock($tenantId, $id);

            if (! $item->isHeld()) {
                throw AccountsPayableException::conflict('open_item_not_held');
            }

            $from = $item->statusEnum();
            $to = $this->statusAfterRelease($item);
            $item->update(['status' => $to->value, 'status_before_hold' => null, 'hold_reason' => null, 'held_at' => null, 'held_by' => null]);
            $this->record($item, 'released', $from, $to, $reason, $actorId, ['held_reason' => $from === OpenItemStatus::Disputed ? 'dispute' : 'administrative_block']);

            return $this->find($tenantId, $id);
        });
    }

    /**
     * Records a change of status in the history of an item.
     *
     * @param  array<string, mixed>  $context
     */
    public function record(ApOpenItem $item, string $event, ?OpenItemStatus $from, ?OpenItemStatus $to, ?string $reason, ?string $actorId, array $context = []): ApOpenItemEvent
    {
        return ApOpenItemEvent::query()->create([
            'tenant_id' => $item->tenant_id,
            'open_item_id' => $item->id,
            'event' => $event,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'reason' => $reason,
            'actor_id' => $actorId,
            'context' => $context === [] ? null : $context,
            'occurred_at' => now(),
        ]);
    }

    private function lock(string $tenantId, string $id): ApOpenItem
    {
        return ApOpenItem::query()->where('tenant_id', $tenantId)->whereKey($id)->lockForUpdate()->first()
            ?? throw AccountsPayableException::notFound('open_item_not_found');
    }

    /**
     * Back to the status before the hold when a payment run still reserves the item, else open.
     */
    private function statusAfterRelease(ApOpenItem $item): OpenItemStatus
    {
        $before = OpenItemStatus::tryFrom((string) $item->status_before_hold) ?? OpenItemStatus::Open;

        if (! in_array($before, [OpenItemStatus::InProposal, OpenItemStatus::Authorized], true)) {
            return OpenItemStatus::Open;
        }

        $inActiveRun = ApPaymentRunItem::query()
            ->where('open_item_id', $item->id)
            ->whereIn('payment_run_id', ApPaymentRun::query()->whereIn('status', ['draft', 'pending_cfo_approval', 'authorized'])->select('id'))
            ->exists();

        return $inActiveRun ? $before : OpenItemStatus::Open;
    }
}
