<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Services;

use App\Actions\Purchases\RecordSupplierPayment;
use App\Models\PassiveInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AccountsPayable\Enums\OpenItemStatus;
use Modules\AccountsPayable\Enums\PaymentRunStatus;
use Modules\AccountsPayable\Exceptions\AccountsPayableException;
use Modules\AccountsPayable\Models\ApOpenItem;
use Modules\AccountsPayable\Models\ApPaymentRun;
use Modules\AccountsPayable\Models\ApPaymentRunItem;
use Modules\AccountsPayable\Models\ApPaymentRunOffset;
use Modules\AccountsPayable\Models\ApPaymentRunTransfer;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\PaymentLine;
use Modules\Treasury\Models\PaymentProposal;
use Modules\Treasury\Models\PaymentProposalLine;

/**
 * The payment runs (distinte di pagamento) of the supplier ledger.
 *
 *   draft -> pending_cfo_approval -> authorized -> executed
 *                   |                    |
 *                   +-> rejected         +-> cancelled   (a draft can be cancelled, a pending run recalled to draft)
 *
 * The run reserves its open items (`in_proposal`, then `authorized`) so no other run can take them. An item in dispute or
 * under administrative block never enters a run, and a run that holds one cannot be submitted, approved or executed: the
 * item is removed from the draft, or released with an audited reason. The CFO who approves cannot be the person who
 * prepared or submitted the run. Executing records the payments, the cash discounts and the credit-note offsets on the
 * supplier invoices, which posts them in the ledger (the Skonto to the cash-discount account).
 *
 * @phpstan-import-type Plan from PaymentRunPlanner
 */
final class PaymentRunService
{
    public function __construct(
        private readonly OpenItemSyncService $sync,
        private readonly OpenItemService $openItems,
        private readonly SkontoTermsResolver $terms,
        private readonly PaymentRunPlanner $planner,
        private readonly RecordSupplierPayment $supplierPayments,
    ) {}

    /**
     * @param  array{status?: string|null, supplier_id?: string|null}  $filters
     */
    public function paginate(string $tenantId, array $filters, int $perPage): LengthAwarePaginator
    {
        return ApPaymentRun::query()
            ->where('tenant_id', $tenantId)
            ->when($filters['status'] ?? null, static fn ($query, $status) => $query->where('status', $status))
            ->when($filters['supplier_id'] ?? null, static fn ($query, $supplier) => $query->whereIn('id', ApPaymentRunItem::query()->where('supplier_id', $supplier)->select('payment_run_id')))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @throws AccountsPayableException
     */
    public function find(string $tenantId, string $id): ApPaymentRun
    {
        return ApPaymentRun::query()->where('tenant_id', $tenantId)->whereKey($id)->first()
            ?? throw AccountsPayableException::notFound('payment_run_not_found');
    }

    /**
     * What a run with these criteria would contain, without saving it.
     *
     * @param  array{currency: string, cutoff_date: string, execution_date: string, include_skonto?: bool, net_credit_notes?: bool, supplier_ids?: list<string>|null, item_ids?: list<string>|null, set_off_ids?: list<string>|null}  $data
     * @return Plan
     */
    public function preview(string $tenantId, array $data): array
    {
        return $this->proposal($tenantId, $data)['plan'];
    }

    /**
     * The plan of a run and what it was drawn from: the invoices the criteria select (before `item_ids` narrows them down), the
     * open credit notes of their suppliers, and the due invoices that are already reserved by another run.
     *
     * @param  array{currency: string, cutoff_date: string, execution_date: string, include_skonto?: bool, net_credit_notes?: bool, supplier_ids?: list<string>|null, item_ids?: list<string>|null, set_off_ids?: list<string>|null}  $data
     * @return array{plan: Plan, candidates: Collection<int, ApOpenItem>, credits: Collection<int, ApOpenItem>, in_run: Collection<int, ApOpenItem>}
     */
    public function proposal(string $tenantId, array $data): array
    {
        $this->sync->syncTenant($tenantId);
        $cutoff = CarbonImmutable::parse($data['cutoff_date']);
        [$debits, $credits, $excluded] = $this->candidates($tenantId, $data['currency'], $data['supplier_ids'] ?? null, $cutoff);
        $all = $this->plan($debits, $credits, $excluded, $data);
        $selected = array_column(array_filter($all['items'], static fn (array $row): bool => $row['kind'] === 'invoice'), 'open_item_id');
        $candidates = $debits->filter(static fn (ApOpenItem $item): bool => in_array((string) $item->id, $selected, true))->values();
        [$restrictedDebits, $restrictedCredits, $explicit] = $this->restrict($debits, $credits, $data);
        $plan = $explicit || $restrictedCredits->count() !== $credits->count() ? $this->plan($restrictedDebits, $restrictedCredits, $excluded, $data, $explicit) : $all;

        $inRun = ApOpenItem::query()
            ->where('tenant_id', $tenantId)
            ->where('currency', strtoupper($data['currency']))
            ->where('kind', 'invoice')
            ->whereIn('status', [OpenItemStatus::InProposal->value, OpenItemStatus::Authorized->value])
            ->whereDate('due_date', '<=', $cutoff->toDateString())
            ->when(($data['supplier_ids'] ?? null) !== null && $data['supplier_ids'] !== [], static fn ($query) => $query->whereIn('supplier_id', $data['supplier_ids']))
            ->get();

        return [
            'plan' => $plan,
            'candidates' => $candidates,
            'credits' => $credits->filter(static fn (ApOpenItem $credit): bool => $candidates->contains('supplier_id', $credit->supplier_id))->values(),
            'in_run' => $inRun,
        ];
    }

    /**
     * Prepares a draft run and reserves its items.
     *
     * @param  array{currency: string, cutoff_date: string, execution_date: string, bank_account_id?: string|null, include_skonto?: bool, net_credit_notes?: bool, supplier_ids?: list<string>|null, item_ids?: list<string>|null, set_off_ids?: list<string>|null, notes?: string|null}  $data
     *
     * @throws AccountsPayableException when no open item matches
     */
    public function create(string $tenantId, array $data, ?string $actorId): ApPaymentRun
    {
        return DB::transaction(function () use ($tenantId, $data, $actorId): ApPaymentRun {
            $this->sync->syncTenant($tenantId);
            [$debits, $credits, $excluded] = $this->candidates($tenantId, $data['currency'], $data['supplier_ids'] ?? null, CarbonImmutable::parse($data['cutoff_date']), true);
            [$debits, $credits, $explicit] = $this->restrict($debits, $credits, $data);
            $plan = $this->plan($debits, $credits, $excluded, $data, $explicit);

            if ($plan['items'] === []) {
                throw AccountsPayableException::invalid('no_items_match');
            }

            $run = ApPaymentRun::query()->create([
                'tenant_id' => $tenantId,
                'run_number' => 'DP-'.CarbonImmutable::parse($data['execution_date'])->format('Ymd').'-'.strtoupper(Str::random(6)),
                'status' => PaymentRunStatus::Draft->value,
                'currency' => strtoupper($data['currency']),
                'cutoff_date' => $data['cutoff_date'],
                'execution_date' => $data['execution_date'],
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'include_skonto' => (bool) ($data['include_skonto'] ?? true),
                'net_credit_notes' => (bool) ($data['net_credit_notes'] ?? true),
                'supplier_ids' => $data['supplier_ids'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->store($run, $plan, $actorId);

            return $run->refresh();
        });
    }

    /**
     * Takes an invoice out of a draft run: the credit notes, the Skonto and the transfers are worked out again. The last
     * invoice removed cancels the run.
     *
     * @throws AccountsPayableException when the run is not a draft or the item is not an invoice of the run
     */
    public function removeItem(string $tenantId, string $runId, string $openItemId, ?string $actorId): ApPaymentRun
    {
        return DB::transaction(function () use ($tenantId, $runId, $openItemId, $actorId): ApPaymentRun {
            $run = $this->lock($tenantId, $runId);
            $this->assertStatus($run, PaymentRunStatus::Draft);
            $rows = ApPaymentRunItem::query()->where('payment_run_id', $run->id)->get();
            $row = $rows->firstWhere('open_item_id', $openItemId);

            if ($row === null) {
                throw AccountsPayableException::notFound('run_item_not_found');
            }

            if ($row->kind !== 'invoice') {
                throw AccountsPayableException::invalid('credit_note_row_not_removable');
            }

            $keep = $rows->where('kind', 'invoice')->pluck('open_item_id')->reject(static fn (string $id): bool => $id === $openItemId)->values()->all();
            $this->releaseItems($run, $rows, $actorId, 'removed_from_run');
            $this->clear($run);

            if ($keep === []) {
                $run->update(['status' => PaymentRunStatus::Cancelled->value, 'items_count' => 0, 'transfers_count' => 0, 'debit_total' => '0.00', 'credit_netted_total' => '0.00', 'skonto_total' => '0.00', 'net_total' => '0.00']);

                return $run->refresh();
            }

            $items = ApOpenItem::query()->with('passiveInvoice')->where('tenant_id', $tenantId)->whereIn('id', $keep)->get();
            $excluded = $items->filter(static fn (ApOpenItem $item): bool => $item->isHeld())->map(fn (ApOpenItem $item): array => $this->excludedRow($item, $this->holdReason($item)))->values()->all();
            $debits = $items->filter(static fn (ApOpenItem $item): bool => $item->statusEnum() === OpenItemStatus::Open && $item->hasOpenBalance())->values();
            [, $credits] = $this->candidates($tenantId, (string) $run->currency, null, CarbonImmutable::parse($run->cutoff_date), true);
            $plan = $this->plan($debits, $credits->filter(fn (ApOpenItem $credit): bool => $debits->contains('supplier_id', $credit->supplier_id))->values(), $excluded, $this->criteria($run), true);

            if ($plan['items'] === []) {
                $run->update(['status' => PaymentRunStatus::Cancelled->value, 'items_count' => 0, 'transfers_count' => 0, 'debit_total' => '0.00', 'credit_netted_total' => '0.00', 'skonto_total' => '0.00', 'net_total' => '0.00', 'excluded' => $excluded]);

                return $run->refresh();
            }

            $this->store($run, $plan, $actorId);

            return $run->refresh();
        });
    }

    /**
     * Sends the draft to the CFO.
     *
     * @throws AccountsPayableException when the run is not a draft, an item is held or an item changed
     */
    public function submit(string $tenantId, string $runId, ?string $actorId): ApPaymentRun
    {
        return DB::transaction(function () use ($tenantId, $runId, $actorId): ApPaymentRun {
            $run = $this->lock($tenantId, $runId);
            $this->assertStatus($run, PaymentRunStatus::Draft);
            $this->assertPayable($run, OpenItemStatus::InProposal);
            $run->update(['status' => PaymentRunStatus::PendingCfoApproval->value, 'submitted_by' => $actorId, 'submitted_at' => now()]);

            return $run->refresh();
        });
    }

    /**
     * Takes a run back from the CFO to edit it.
     *
     * @throws AccountsPayableException when the run is not waiting for approval
     */
    public function recall(string $tenantId, string $runId): ApPaymentRun
    {
        return DB::transaction(function () use ($tenantId, $runId): ApPaymentRun {
            $run = $this->lock($tenantId, $runId);
            $this->assertStatus($run, PaymentRunStatus::PendingCfoApproval);
            $run->update(['status' => PaymentRunStatus::Draft->value, 'submitted_by' => null, 'submitted_at' => null]);

            return $run->refresh();
        });
    }

    /**
     * The CFO authorizes the run: its items become `authorized`.
     *
     * @throws AccountsPayableException when the approver prepared or submitted the run, or an item is held or changed
     */
    public function approve(string $tenantId, string $runId, string $approverId): ApPaymentRun
    {
        return DB::transaction(function () use ($tenantId, $runId, $approverId): ApPaymentRun {
            $run = $this->lock($tenantId, $runId);
            $this->assertStatus($run, PaymentRunStatus::PendingCfoApproval);

            if ($approverId === (string) $run->created_by || $approverId === (string) $run->submitted_by) {
                throw AccountsPayableException::forbidden('segregation_of_duties');
            }

            $this->assertPayable($run, OpenItemStatus::InProposal);

            foreach ($this->runItems($run) as $item) {
                $item->update(['status' => OpenItemStatus::Authorized->value]);
                $this->openItems->record($item, 'authorized', OpenItemStatus::InProposal, OpenItemStatus::Authorized, null, $approverId, ['payment_run_id' => $run->id, 'run_number' => $run->run_number]);
            }

            $run->update(['status' => PaymentRunStatus::Authorized->value, 'approved_by' => $approverId, 'approved_at' => now()]);

            return $run->refresh();
        });
    }

    /**
     * The CFO refuses the run: its items are free again.
     *
     * @throws AccountsPayableException when the run is not waiting for approval
     */
    public function reject(string $tenantId, string $runId, string $rejectedBy, string $reason): ApPaymentRun
    {
        return DB::transaction(function () use ($tenantId, $runId, $rejectedBy, $reason): ApPaymentRun {
            $run = $this->lock($tenantId, $runId);
            $this->assertStatus($run, PaymentRunStatus::PendingCfoApproval);
            $this->releaseItems($run, ApPaymentRunItem::query()->where('payment_run_id', $run->id)->get(), $rejectedBy, 'run_rejected', $reason);
            $run->update(['status' => PaymentRunStatus::Rejected->value, 'rejected_by' => $rejectedBy, 'rejected_at' => now(), 'rejection_reason' => $reason]);

            return $run->refresh();
        });
    }

    /**
     * Drops a run that was not executed: its items are free again.
     *
     * @throws AccountsPayableException when the run is already executed, rejected or cancelled
     */
    public function cancel(string $tenantId, string $runId, ?string $actorId, ?string $reason = null): ApPaymentRun
    {
        return DB::transaction(function () use ($tenantId, $runId, $actorId, $reason): ApPaymentRun {
            $run = $this->lock($tenantId, $runId);

            if (! $run->statusEnum()->isActive()) {
                throw AccountsPayableException::conflict('run_status_invalid', ['status' => $run->status]);
            }

            $this->releaseItems($run, ApPaymentRunItem::query()->where('payment_run_id', $run->id)->get(), $actorId, 'run_cancelled', $reason);
            $run->update(['status' => PaymentRunStatus::Cancelled->value, 'notes' => $reason !== null ? trim($run->notes."\n".$reason) : $run->notes]);

            return $run->refresh();
        });
    }

    /**
     * Pays an authorized run: records on every invoice the cash transfer, the cash discount and the credit notes offset,
     * which posts them in the ledger, and closes the items it settles.
     *
     * @throws AccountsPayableException when the run is not authorized, an item is held or an item changed since approval
     */
    public function execute(string $tenantId, string $runId, ?string $actorId): ApPaymentRun
    {
        return DB::transaction(function () use ($tenantId, $runId, $actorId): ApPaymentRun {
            $run = $this->lock($tenantId, $runId);

            if ($run->statusEnum() !== PaymentRunStatus::Authorized) {
                throw AccountsPayableException::conflict('run_not_authorized', ['status' => $run->status]);
            }

            $this->assertPayable($run, OpenItemStatus::Authorized);
            $paidAt = $run->execution_date->toDateString();
            $ledger = ['iban' => $run->bank_account_id !== null ? BankAccount::query()->whereKey($run->bank_account_id)->value('iban') : null];
            $offsets = ApPaymentRunOffset::query()->where('payment_run_id', $run->id)->get();
            $rows = ApPaymentRunItem::query()->where('payment_run_id', $run->id)->get();
            $items = ApOpenItem::query()->with('passiveInvoice')->where('tenant_id', $tenantId)->whereIn('id', $rows->pluck('open_item_id'))->lockForUpdate()->get()->keyBy('id');

            foreach ($rows->where('kind', 'invoice') as $row) {
                /** @var ApOpenItem $item */
                $item = $items->get($row->open_item_id);
                $debitOffsets = $offsets->where('debit_item_id', $row->open_item_id);

                if ($item->passive_invoice_id !== null) {
                    $this->payInvoice($item, $row, $debitOffsets, $items, $paidAt, (string) $run->run_number, $ledger);

                    continue;
                }

                $this->applyManual($item, Decimal::add((string) $row->cash_amount, (string) $row->skonto_amount), (string) $row->skonto_amount);

                foreach ($debitOffsets as $offset) {
                    $this->applyManual($item, (string) $offset->amount, '0.00');
                    $this->applyManual($items->get($offset->credit_item_id), (string) $offset->amount, '0.00');
                }
            }

            foreach ($items as $item) {
                $this->closeItem($item, $run, $actorId);
            }

            ApPaymentRunTransfer::query()->where('payment_run_id', $run->id)->where('status', ApPaymentRunTransfer::StatusPending)->update(['status' => ApPaymentRunTransfer::StatusPaid]);
            $run->update(['status' => PaymentRunStatus::Executed->value, 'executed_by' => $actorId, 'executed_at' => now()]);

            return $run->refresh();
        });
    }

    /**
     * The items, transfers and offsets of a run, for the resources.
     */
    public function load(ApPaymentRun $run): ApPaymentRun
    {
        $run->load(['items', 'transfers', 'offsets']);
        $numbers = ApOpenItem::query()->whereIn('id', $run->items->pluck('open_item_id'))->get(['id', 'document_number', 'supplier_id', 'status'])->keyBy('id');

        foreach ($run->items as $row) {
            $row->setAttribute('document_number', $numbers->get($row->open_item_id)?->document_number);
            $row->setAttribute('item_status', $numbers->get($row->open_item_id)?->status);
        }

        return $run;
    }

    /**
     * Narrows the candidates to the items ticked by the user: `item_ids` picks the invoices (which are then taken whether or not
     * they are due), `set_off_ids` the credit notes that may be offset. A key that is not given leaves everything in.
     *
     * @param  Collection<int, ApOpenItem>  $debits
     * @param  Collection<int, ApOpenItem>  $credits
     * @param  array<string, mixed>  $data
     * @return array{0: Collection<int, ApOpenItem>, 1: Collection<int, ApOpenItem>, 2: bool} the invoices, the credit notes and whether the invoices were picked one by one
     */
    private function restrict(Collection $debits, Collection $credits, array $data): array
    {
        $itemIds = isset($data['item_ids']) && is_array($data['item_ids']) ? array_map('strval', $data['item_ids']) : null;
        $setOffIds = isset($data['set_off_ids']) && is_array($data['set_off_ids']) ? array_map('strval', $data['set_off_ids']) : null;

        return [
            $itemIds === null ? $debits : $debits->filter(static fn (ApOpenItem $item): bool => in_array((string) $item->id, $itemIds, true))->values(),
            $setOffIds === null ? $credits : $credits->filter(static fn (ApOpenItem $credit): bool => in_array((string) $credit->id, $setOffIds, true))->values(),
            $itemIds !== null,
        ];
    }

    /**
     * @param  Collection<int, ApOpenItem>  $debits
     * @param  Collection<int, ApOpenItem>  $credits
     * @param  list<array{open_item_id: string, document_number: string, supplier_id: string, reason: string}>  $excluded
     * @param  array{currency: string, cutoff_date: string, execution_date: string, include_skonto?: bool, net_credit_notes?: bool}  $data
     * @return Plan
     */
    private function plan(Collection $debits, Collection $credits, array $excluded, array $data, bool $selectAll = false): array
    {
        return $this->planner->plan(
            $debits,
            $credits,
            $this->terms->forItems($debits),
            CarbonImmutable::parse($data['cutoff_date']),
            CarbonImmutable::parse($data['execution_date']),
            (bool) ($data['include_skonto'] ?? true),
            (bool) ($data['net_credit_notes'] ?? true),
            strtoupper($data['currency']),
            $excluded,
            $selectAll,
        );
    }

    /**
     * The invoices and credit notes that may be proposed, and the due invoices left out because they are held or not
     * approved for payment.
     *
     * @param  list<string>|null  $supplierIds
     * @return array{0: Collection<int, ApOpenItem>, 1: Collection<int, ApOpenItem>, 2: list<array{open_item_id: string, document_number: string, supplier_id: string, reason: string}>}
     */
    private function candidates(string $tenantId, string $currency, ?array $supplierIds, CarbonImmutable $cutoff, bool $lock = false): array
    {
        $query = ApOpenItem::query()
            ->with('passiveInvoice')
            ->where('tenant_id', $tenantId)
            ->where('currency', strtoupper($currency))
            ->where('open_amount', '>', 0)
            ->whereIn('status', [OpenItemStatus::Open->value, OpenItemStatus::Disputed->value, OpenItemStatus::Blocked->value])
            ->when($supplierIds !== null && $supplierIds !== [], static fn ($q) => $q->whereIn('supplier_id', $supplierIds))
            ->orderBy('due_date')
            ->orderBy('document_number');

        $items = $lock ? $query->lockForUpdate()->get() : $query->get();
        $busy = $this->busyInTreasury($items->pluck('passive_invoice_id')->filter()->all());
        $debits = collect();
        $credits = collect();
        $excluded = [];

        foreach ($items as $item) {
            $notApproved = $item->passive_invoice_id !== null && $item->passiveInvoice?->isPaymentBlocked() === true;

            if ($item->isCreditNote()) {
                if ($item->statusEnum() === OpenItemStatus::Open && ! $notApproved) {
                    $credits->push($item);
                }

                continue;
            }

            $isDue = $item->due_date->lte($cutoff);

            if ($item->passive_invoice_id !== null && in_array($item->passive_invoice_id, $busy, true)) {
                if ($isDue) {
                    $excluded[] = $this->excludedRow($item, 'in_treasury_proposal');
                }

                continue;
            }

            if ($item->isHeld()) {
                if ($isDue) {
                    $excluded[] = $this->excludedRow($item, $this->holdReason($item));
                }

                continue;
            }

            if ($notApproved) {
                if ($isDue) {
                    $excluded[] = $this->excludedRow($item, 'invoice_not_approved');
                }

                continue;
            }

            $debits->push($item);
        }

        return [$debits, $credits, $excluded];
    }

    /**
     * The invoices a treasury payment proposal or an open payment batch already holds: they are paid there.
     *
     * @param  list<string>  $invoiceIds
     * @return list<string>
     */
    private function busyInTreasury(array $invoiceIds): array
    {
        if ($invoiceIds === []) {
            return [];
        }

        $proposals = PaymentProposalLine::query()
            ->join('treasury_payment_proposals as proposal', 'proposal.id', '=', 'treasury_payment_proposal_lines.payment_proposal_id')
            ->where('proposal.status', PaymentProposal::Draft)
            ->whereNull('proposal.deleted_at')
            ->whereIn('treasury_payment_proposal_lines.passive_invoice_id', $invoiceIds)
            ->pluck('treasury_payment_proposal_lines.passive_invoice_id');
        $batches = PaymentLine::query()
            ->join('payment_batches as batch', 'batch.id', '=', 'payment_lines.payment_batch_id')
            ->where('payment_lines.status', 'pending')
            ->whereNotIn('batch.status', ['rejected', 'cancelled', 'canceled'])
            ->whereIn('payment_lines.invoice_id', $invoiceIds)
            ->pluck('payment_lines.invoice_id');

        return $proposals->merge($batches)->map(static fn (mixed $id): string => (string) $id)->unique()->values()->all();
    }

    /**
     * @return array{open_item_id: string, document_number: string, supplier_id: string, reason: string}
     */
    private function excludedRow(ApOpenItem $item, string $reason): array
    {
        return ['open_item_id' => (string) $item->id, 'document_number' => (string) $item->document_number, 'supplier_id' => (string) $item->supplier_id, 'reason' => $reason];
    }

    private function holdReason(ApOpenItem $item): string
    {
        return $item->statusEnum() === OpenItemStatus::Disputed ? 'disputed' : 'administrative_block';
    }

    /**
     * @return array{currency: string, cutoff_date: string, execution_date: string, include_skonto: bool, net_credit_notes: bool}
     */
    private function criteria(ApPaymentRun $run): array
    {
        return [
            'currency' => (string) $run->currency,
            'cutoff_date' => $run->cutoff_date->toDateString(),
            'execution_date' => $run->execution_date->toDateString(),
            'include_skonto' => (bool) $run->include_skonto,
            'net_credit_notes' => (bool) $run->net_credit_notes,
        ];
    }

    /**
     * Saves a plan in the run and reserves its items.
     *
     * @param  Plan  $plan
     */
    private function store(ApPaymentRun $run, array $plan, ?string $actorId): void
    {
        $transferIds = [];

        foreach ($plan['transfers'] as $transfer) {
            $record = ApPaymentRunTransfer::query()->create([
                'tenant_id' => $run->tenant_id,
                'payment_run_id' => $run->id,
                'supplier_id' => $transfer['supplier_id'],
                'creditor_iban' => $transfer['creditor_iban'],
                'currency' => $transfer['currency'],
                'gross_amount' => $transfer['gross_amount'],
                'skonto_amount' => $transfer['skonto_amount'],
                'credit_netted_amount' => $transfer['credit_netted_amount'],
                'amount' => $transfer['amount'],
                'items_count' => count($transfer['item_ids']),
                'reference' => Str::limit($run->run_number.' '.implode(', ', $transfer['document_numbers']), 140, ''),
                'status' => Decimal::isPositive($transfer['amount']) ? ApPaymentRunTransfer::StatusPending : ApPaymentRunTransfer::StatusNetted,
            ]);

            foreach ($transfer['item_ids'] as $itemId) {
                $transferIds[$itemId] = (string) $record->id;
            }
        }

        foreach ($plan['items'] as $row) {
            ApPaymentRunItem::query()->create([
                'tenant_id' => $run->tenant_id,
                'payment_run_id' => $run->id,
                'open_item_id' => $row['open_item_id'],
                'transfer_id' => $transferIds[$row['open_item_id']] ?? null,
                'supplier_id' => $row['supplier_id'],
                'kind' => $row['kind'],
                'selection_reason' => $row['selection_reason'],
                'open_amount' => $row['open_amount'],
                'skonto_percentage' => $row['skonto_percentage'],
                'skonto_deadline' => $row['skonto_deadline'],
                'skonto_amount' => $row['skonto_amount'],
                'credit_applied_amount' => $row['credit_applied_amount'],
                'cash_amount' => $row['cash_amount'],
                'credit_used_amount' => $row['credit_used_amount'],
            ]);

            $item = ApOpenItem::query()->whereKey($row['open_item_id'])->firstOrFail();
            $item->update(['status' => OpenItemStatus::InProposal->value]);
            $this->openItems->record($item, 'proposed', OpenItemStatus::Open, OpenItemStatus::InProposal, null, $actorId, ['payment_run_id' => $run->id, 'run_number' => $run->run_number, 'selection_reason' => $row['selection_reason']]);
        }

        foreach ($plan['offsets'] as $offset) {
            ApPaymentRunOffset::query()->create(['tenant_id' => $run->tenant_id, 'payment_run_id' => $run->id] + $offset);
        }

        $run->update($plan['totals'] + ['excluded' => $plan['excluded'] === [] ? null : $plan['excluded']]);
    }

    /**
     * Deletes the plan of a draft run (its items, transfers and offsets), before it is worked out again.
     */
    private function clear(ApPaymentRun $run): void
    {
        ApPaymentRunOffset::query()->where('payment_run_id', $run->id)->forceDelete();
        ApPaymentRunItem::query()->where('payment_run_id', $run->id)->forceDelete();
        ApPaymentRunTransfer::query()->where('payment_run_id', $run->id)->forceDelete();
    }

    /**
     * Frees the items of a run that leaves the pipeline. An item that was put on hold meanwhile keeps its hold.
     *
     * @param  Collection<int, ApPaymentRunItem>  $rows
     */
    private function releaseItems(ApPaymentRun $run, Collection $rows, ?string $actorId, string $event, ?string $reason = null): void
    {
        $items = ApOpenItem::query()->where('tenant_id', $run->tenant_id)->whereIn('id', $rows->pluck('open_item_id'))->lockForUpdate()->get();

        foreach ($items as $item) {
            if (! in_array($item->status, [OpenItemStatus::InProposal->value, OpenItemStatus::Authorized->value], true)) {
                if ($item->isHeld() && in_array($item->status_before_hold, [OpenItemStatus::InProposal->value, OpenItemStatus::Authorized->value], true)) {
                    $item->update(['status_before_hold' => OpenItemStatus::Open->value]);
                }

                continue;
            }

            $from = $item->statusEnum();
            $item->update(['status' => OpenItemStatus::Open->value]);
            $this->openItems->record($item, $event, $from, OpenItemStatus::Open, $reason, $actorId, ['payment_run_id' => $run->id, 'run_number' => $run->run_number]);
        }
    }

    /**
     * The open items of the run, locked.
     *
     * @return Collection<int, ApOpenItem>
     */
    private function runItems(ApPaymentRun $run): Collection
    {
        return ApOpenItem::query()
            ->where('tenant_id', $run->tenant_id)
            ->whereIn('id', ApPaymentRunItem::query()->where('payment_run_id', $run->id)->select('open_item_id'))
            ->lockForUpdate()
            ->get();
    }

    /**
     * The preventive payment block: a run holding an item in dispute or under administrative block cannot move on, and an
     * item whose balance changed since the run was prepared (a payment recorded elsewhere) must be proposed again.
     *
     * @throws AccountsPayableException
     */
    private function assertPayable(ApPaymentRun $run, OpenItemStatus $expected): void
    {
        $rows = ApPaymentRunItem::query()->where('payment_run_id', $run->id)->get()->keyBy('open_item_id');
        $this->refreshBalances($run);
        $items = $this->runItems($run);
        $held = $items->filter(static fn (ApOpenItem $item): bool => $item->isHeld());

        if ($held->isNotEmpty()) {
            throw AccountsPayableException::conflict('run_contains_held_items', ['documents' => $held->pluck('document_number')->implode(', ')]);
        }

        $notApproved = $items->filter(fn (ApOpenItem $item): bool => $item->passive_invoice_id !== null && PassiveInvoice::query()->whereKey($item->passive_invoice_id)->first()?->isPaymentBlocked() === true);

        if ($notApproved->isNotEmpty()) {
            throw AccountsPayableException::conflict('run_contains_unapproved_invoices', ['documents' => $notApproved->pluck('document_number')->implode(', ')]);
        }

        foreach ($items as $item) {
            $row = $rows->get($item->id);
            $balance = (string) $item->open_amount;
            $changed = $item->statusEnum() !== $expected
                || ($row->kind === 'invoice' && Decimal::compare($balance, (string) $row->open_amount) !== 0)
                || ($row->kind === 'credit_note' && Decimal::compare($balance, (string) $row->credit_used_amount) < 0);

            if ($changed) {
                throw AccountsPayableException::conflict('run_item_changed', ['document' => $item->document_number]);
            }
        }
    }

    /**
     * Brings the balances of the items of the run in line with their invoices, which may have been paid elsewhere.
     */
    private function refreshBalances(ApPaymentRun $run): void
    {
        foreach ($this->runItems($run) as $item) {
            $invoice = $item->passive_invoice_id !== null ? PassiveInvoice::query()->with('supplier')->where('tenant_id', $run->tenant_id)->find($item->passive_invoice_id) : null;

            if ($invoice !== null) {
                $this->sync->syncInvoice($invoice);
            }
        }
    }

    /**
     * @throws AccountsPayableException
     */
    private function assertStatus(ApPaymentRun $run, PaymentRunStatus $expected): void
    {
        if ($run->statusEnum() !== $expected) {
            throw AccountsPayableException::conflict('run_status_invalid', ['status' => $run->status]);
        }
    }

    private function lock(string $tenantId, string $runId): ApPaymentRun
    {
        return ApPaymentRun::query()->where('tenant_id', $tenantId)->whereKey($runId)->lockForUpdate()->first()
            ?? throw AccountsPayableException::notFound('payment_run_not_found');
    }

    /**
     * Records the settlement of one supplier invoice: the cash transfer, the cash discount and the credit notes offset.
     *
     * @param  Collection<int, ApPaymentRunOffset>  $offsets
     * @param  Collection<string, ApOpenItem>  $items
     * @param  array{iban: string|null}  $ledger
     */
    private function payInvoice(ApOpenItem $item, ApPaymentRunItem $row, Collection $offsets, Collection $items, string $paidAt, string $runNumber, array $ledger): void
    {
        $invoice = PassiveInvoice::query()->where('tenant_id', $item->tenant_id)->findOrFail($item->passive_invoice_id);
        $common = ['paid_at' => $paidAt, 'reference' => $runNumber, 'ledger_options' => $ledger];

        if (Decimal::isPositive((string) $row->cash_amount)) {
            $this->supplierPayments->execute($invoice, $common + ['amount' => (string) $row->cash_amount, 'type' => 'payment', 'notes' => 'Payment run']);
        }

        if (Decimal::isPositive((string) $row->skonto_amount)) {
            $this->supplierPayments->execute($invoice, $common + ['amount' => (string) $row->skonto_amount, 'type' => 'cash_discount', 'notes' => 'Cash discount (Skonto)']);
        }

        foreach ($offsets as $offset) {
            /** @var ApOpenItem|null $credit */
            $credit = $items->get($offset->credit_item_id);
            $creditInvoice = $credit?->passive_invoice_id !== null ? PassiveInvoice::query()->where('tenant_id', $item->tenant_id)->findOrFail($credit->passive_invoice_id) : null;

            $this->supplierPayments->execute($invoice, $common + ['amount' => (string) $offset->amount, 'type' => 'credit_offset', 'offset_passive_invoice_id' => $creditInvoice?->id, 'notes' => 'Credit note offset']);

            if ($creditInvoice !== null) {
                $this->supplierPayments->execute($creditInvoice, $common + ['amount' => (string) $offset->amount, 'type' => 'credit_used', 'offset_passive_invoice_id' => $invoice->id, 'notes' => 'Offset against '.$invoice->invoice_number]);
            }
        }
    }

    /**
     * A manual item is not in the ledger: only its own balance changes.
     */
    private function applyManual(ApOpenItem $item, string $amount, string $discount): void
    {
        $paid = Decimal::round2(Decimal::add((string) $item->paid_amount, $amount));
        $item->forceFill([
            'paid_amount' => $paid,
            'discount_taken_amount' => Decimal::round2(Decimal::add((string) $item->discount_taken_amount, $discount)),
            'open_amount' => Decimal::round2(Decimal::sub((string) $item->original_amount, $paid)),
        ])->save();
    }

    /**
     * After the payments: the item is paid when nothing is left, else it goes back to open (a credit note used in part).
     */
    private function closeItem(ApOpenItem $item, ApPaymentRun $run, ?string $actorId): void
    {
        $item = $item->passive_invoice_id !== null && $item->passiveInvoice !== null ? $this->sync->syncInvoice($item->passiveInvoice->refresh()) : $item->refresh();
        $from = OpenItemStatus::Authorized;
        $to = $item->hasOpenBalance() ? OpenItemStatus::Open : OpenItemStatus::Paid;
        $item->update(['status' => $to->value]);
        $this->openItems->record($item, $to === OpenItemStatus::Paid ? 'paid' : 'partially_paid', $from, $to, null, $actorId, ['payment_run_id' => $run->id, 'run_number' => $run->run_number]);
    }
}
