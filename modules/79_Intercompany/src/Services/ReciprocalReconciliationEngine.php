<?php

declare(strict_types=1);

namespace Modules\Intercompany\Services;

use App\Models\PassiveInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Exceptions\ConsolidationException;
use Modules\GeneralLedger\Models\ConsolidationGroup;
use Modules\GeneralLedger\Services\Consolidation\ConsolidationMath;
use Modules\GeneralLedger\Services\Consolidation\ConsolidationTranslator;
use Modules\Intercompany\Enums\AnomalyType;
use Modules\Intercompany\Enums\MatchStatus;
use Modules\Intercompany\Exceptions\IntercompanyConflictException;
use Modules\Intercompany\Exceptions\IntercompanyException;
use Modules\Intercompany\Models\GroupSettings;
use Modules\Intercompany\Models\PartyLink;
use Modules\Intercompany\Models\ReconciliationBatch;
use Modules\Intercompany\Models\ReconciliationItem;
use Modules\Intercompany\Models\TransactionLink;
use Modules\Intercompany\Support\GroupScope;
use Modules\Invoicing\Models\SalesInvoice;

/**
 * Document level reconciliation of the reciprocal balances of a group (riconciliazione partite reciproche): every open
 * document an entity issued to another entity of the group (receivable) against the supplier document the counterparty
 * booked (payable).
 *
 * Matching, in this order, for each document of A: the mirror recorded by the mirror engine; the supplier document with
 * the same number from the same supplier contact; the supplier document of the same currency whose amount is within the
 * amount tolerance and whose date is within the date tolerance (match kind `tolerance`). Supplier documents that no
 * document of A explains stay as `missing_counterpart` items of the other direction.
 *
 * Classification of a pair, first rule that applies:
 *  - no counterpart: `timing` (reason `in_transit`) while the document is not older than the date tolerance, else
 *    `missing_counterpart`;
 *  - the currencies differ, or the exchange rates of the two documents differ and move the group-currency difference
 *    beyond the tolerance: `fx`;
 *  - the net amounts differ beyond the tolerance: `amount`; the net amounts agree but the tax differs: `tax`;
 *  - the amounts agree but the counterpart is still a draft, was booked outside the date tolerance, or the settlement
 *    status differs (payment lag): `timing`.
 * No anomaly means `matched`; any anomaly leaves the pair `unmatched` until it is matched, disputed, written off or adjusted
 * by hand. Nothing is written in the books of the entities.
 */
final readonly class ReciprocalReconciliationEngine
{
    public function __construct(
        private GroupScope $scope,
        private SettingsService $settings,
        private ConsolidationTranslator $translator,
    ) {}

    public function run(User $user, ConsolidationGroup $group, CarbonImmutable $asOf, bool $autoMatch = true): ReconciliationBatch
    {
        $this->scope->assertReadable($user, $group);

        return $this->scope->runAs((string) $group->tenant_id, function () use ($group, $asOf, $autoMatch): ReconciliationBatch {
            $settings = $this->settings->forGroup($group);

            return DB::transaction(function () use ($group, $settings, $asOf, $autoMatch): ReconciliationBatch {
                $batch = ReconciliationBatch::withoutGlobalScopes()->create(['tenant_id' => $group->tenant_id, 'group_id' => $group->id, 'as_of_date' => $asOf->toDateString(), 'status' => 'open']);

                if ($autoMatch) {
                    $this->matchAll($group, $settings, $batch, $asOf);
                }

                $batch->forceFill(['totals' => $this->totalsOf($batch)])->save();

                return $batch->refresh();
            });
        });
    }

    /**
     * Matches a document of entity A by hand with a supplier document of entity B.
     */
    public function match(User $user, ConsolidationGroup $group, ReconciliationItem $item, string $counterpartDocumentId): ReconciliationItem
    {
        $this->scope->assertReadable($user, $group);

        return $this->scope->runAs((string) $group->tenant_id, function () use ($user, $group, $item, $counterpartDocumentId): ReconciliationItem {
            $this->assertOpen($item);
            $settings = $this->settings->forGroup($group);
            $b = PassiveInvoice::withoutGlobalScopes()->where('tenant_id', $item->entity_b_tenant_id)->find($counterpartDocumentId);

            if ($b === null) {
                throw new IntercompanyException('The counterpart document does not exist in the books of the second entity.', 'counterpart_missing');
            }

            $holders = ReconciliationItem::withoutGlobalScopes()->where('batch_id', $item->batch_id)->where('b_document_id', $b->id)->where('id', '!=', $item->id)->get();

            if ($holders->contains(fn (ReconciliationItem $holder): bool => $holder->a_document_id !== null)) {
                throw new IntercompanyConflictException('The counterpart document is already matched in this batch.', 'counterpart_taken');
            }

            // A supplier document that no invoice explained is listed as an item of its own: matching it consumes that item.
            $holders->each(fn (ReconciliationItem $holder): ?bool => $holder->delete());

            $this->fillCounterpart($item, $b);
            $verdict = $this->classify($group, $settings, $item, CarbonImmutable::parse((string) ReconciliationBatch::withoutGlobalScopes()->whereKey($item->batch_id)->value('as_of_date')), $b);
            $item->forceFill([
                'status' => MatchStatus::Matched->value,
                'match_kind' => 'manual',
                'anomaly_type' => $verdict['type']->value,
                'anomaly_reason' => $verdict['reason'],
                'difference' => $verdict['difference'],
                'difference_group_currency' => $verdict['difference_group'],
                'resolved_by' => $user->id,
                'resolved_at' => now(),
            ])->save();
            $this->refreshTotals($item);

            return $item->refresh();
        });
    }

    public function unmatch(User $user, ConsolidationGroup $group, ReconciliationItem $item): ReconciliationItem
    {
        $this->scope->assertReadable($user, $group);

        return $this->scope->runAs((string) $group->tenant_id, function () use ($item): ReconciliationItem {
            if ($item->status === MatchStatus::WrittenOff->value) {
                throw new IntercompanyConflictException('A written off item cannot be reopened.', 'item_closed');
            }

            $item->forceFill([
                'b_document_id' => null, 'b_document_number' => null, 'b_document_date' => null, 'b_currency' => null, 'b_amount' => null, 'b_tax' => null,
                'status' => MatchStatus::Unmatched->value,
                'anomaly_type' => AnomalyType::MissingCounterpart->value,
                'anomaly_reason' => 'unmatched_by_user',
                'match_kind' => null, 'resolution_reason' => null, 'adjustment_amount' => null, 'resolved_by' => null, 'resolved_at' => null,
                'difference' => $item->a_amount ?? ConsolidationMath::zero(),
            ])->save();
            $this->refreshTotals($item);

            return $item->refresh();
        });
    }

    public function dispute(User $user, ConsolidationGroup $group, ReconciliationItem $item, string $reason): ReconciliationItem
    {
        return $this->resolve($user, $group, $item, MatchStatus::Disputed, $reason);
    }

    public function writeOff(User $user, ConsolidationGroup $group, ReconciliationItem $item, string $reason): ReconciliationItem
    {
        return $this->resolve($user, $group, $item, MatchStatus::WrittenOff, $reason);
    }

    public function adjust(User $user, ConsolidationGroup $group, ReconciliationItem $item, string $reason, string $amount): ReconciliationItem
    {
        return $this->resolve($user, $group, $item, MatchStatus::Adjusted, $reason, ConsolidationMath::norm($amount));
    }

    /**
     * Quadrature matrix of the reciprocal balances: for every ordered pair of entities, what A is owed by B against what B
     * owes A, open at the date, in group currency, with the anomalies of the latest batch of that date.
     *
     * @return array<string, mixed>
     */
    public function matrix(User $user, ConsolidationGroup $group, CarbonImmutable $asOf): array
    {
        $this->scope->assertReadable($user, $group);

        return $this->scope->runAs((string) $group->tenant_id, function () use ($group, $asOf): array {
            $settings = $this->settings->forGroup($group);
            $entities = $this->scope->entityIds($group);
            $names = $this->scope->names($entities);
            $batch = ReconciliationBatch::withoutGlobalScopes()->where('tenant_id', $group->tenant_id)->where('group_id', $group->id)->whereDate('as_of_date', $asOf->toDateString())->latest('created_at')->first();
            $cells = [];

            foreach (PartyLink::withoutGlobalScopes()->where('tenant_id', $group->tenant_id)->where('group_id', $group->id)->where('role', 'customer')->get() as $party) {
                $a = (string) $party->entity_tenant_id;
                $b = (string) $party->counterparty_tenant_id;
                $receivable = $this->openReceivable($a, (string) $party->contact_id, $asOf, (string) $group->group_currency);
                $supplier = $this->supplierContact($group, $b, $a);
                $payable = $supplier === null ? ['group' => ConsolidationMath::zero(), 'count' => 0] : $this->openPayable($b, $supplier, $asOf, (string) $group->group_currency);
                $difference = ConsolidationMath::sub($receivable['group'], $payable['group']);
                $counts = array_fill_keys(['timing', 'fx', 'amount', 'tax', 'missing_counterpart'], 0);

                if ($batch !== null) {
                    $rows = ReconciliationItem::withoutGlobalScopes()->where('batch_id', $batch->id)->where('entity_a_tenant_id', $a)->where('entity_b_tenant_id', $b)->get();

                    foreach ($rows as $row) {
                        if (isset($counts[$row->anomaly_type]) && ! in_array($row->status, [MatchStatus::WrittenOff->value, MatchStatus::Adjusted->value], true)) {
                            $counts[$row->anomaly_type]++;
                        }
                    }
                }

                $cells[] = [
                    'entity_a_tenant_id' => $a,
                    'entity_b_tenant_id' => $b,
                    'receivable_a' => $receivable['group'],
                    'payable_b' => $payable['group'],
                    'difference' => $difference,
                    'anomaly_counts' => $counts,
                    'status' => ConsolidationMath::cmp(ConsolidationMath::abs($difference), (string) $settings->amount_tolerance) <= 0 && array_sum($counts) === 0 ? 'matched' : 'difference',
                    'open_items' => $receivable['count'],
                ];
            }

            return [
                'currency' => (string) $group->group_currency,
                'as_of_date' => $asOf->toDateString(),
                'entities' => array_map(static fn (string $id): array => ['tenant_id' => $id, 'name' => $names[$id] ?? $id], $entities),
                'cells' => $cells,
            ];
        });
    }

    private function matchAll(ConsolidationGroup $group, GroupSettings $settings, ReconciliationBatch $batch, CarbonImmutable $asOf): void
    {
        $used = [];

        foreach (PartyLink::withoutGlobalScopes()->where('tenant_id', $group->tenant_id)->where('group_id', $group->id)->where('role', 'customer')->get() as $party) {
            $a = (string) $party->entity_tenant_id;
            $b = (string) $party->counterparty_tenant_id;
            $supplier = $this->supplierContact($group, $b, $a);
            $candidates = $supplier === null ? collect() : PassiveInvoice::withoutGlobalScopes()->where('tenant_id', $b)->where('supplier_id', $supplier)->whereDate('date', '<=', $asOf->toDateString())->orderBy('date')->get();

            $documents = SalesInvoice::withoutGlobalScopes()
                ->where('tenant_id', $a)->where('contact_id', $party->contact_id)->where('status', 'posted')->where('invoice_type', '!=', 'proforma')
                ->whereDate('date', '<=', $asOf->toDateString())->where('payment_status', '!=', 'paid')->orderBy('date')->get();

            foreach ($documents as $document) {
                $counterpart = $this->findCounterpart($group, $settings, $document, $candidates, $used);
                $item = $this->newItem($group, $batch, $a, $b, $document);

                if ($counterpart['document'] !== null) {
                    $used[(string) $counterpart['document']->id] = true;
                    $this->fillCounterpart($item, $counterpart['document']);
                }

                $verdict = $this->classify($group, $settings, $item, $asOf, $counterpart['document']);
                $item->forceFill([
                    'anomaly_type' => $verdict['type']->value,
                    'anomaly_reason' => $verdict['reason'],
                    'difference' => $verdict['difference'],
                    'difference_group_currency' => $verdict['difference_group'],
                    'status' => $verdict['type'] === AnomalyType::None ? MatchStatus::Matched->value : MatchStatus::Unmatched->value,
                    'match_kind' => $verdict['type'] === AnomalyType::None ? $counterpart['kind'] : null,
                ])->save();
            }

            foreach ($candidates as $passive) {
                if (isset($used[(string) $passive->id]) || $passive->payment_status === 'paid') {
                    continue;
                }

                $this->orphan($group, $batch, $a, $b, $passive);
            }
        }
    }

    /**
     * @param  Collection<int, PassiveInvoice>  $candidates
     * @param  array<string, true>  $used
     * @return array{document: ?PassiveInvoice, kind: ?string}
     */
    private function findCounterpart(ConsolidationGroup $group, GroupSettings $settings, SalesInvoice $document, Collection $candidates, array $used): array
    {
        $link = TransactionLink::withoutGlobalScopes()->where('tenant_id', $group->tenant_id)->where('source_document_id', $document->id)->whereNotNull('mirror_document_id')->first();

        if ($link !== null) {
            $mirror = $candidates->firstWhere('id', $link->mirror_document_id);

            if ($mirror !== null && ! isset($used[(string) $mirror->id])) {
                return ['document' => $mirror, 'kind' => 'exact'];
            }
        }

        $free = $candidates->reject(fn (PassiveInvoice $passive): bool => isset($used[(string) $passive->id]));
        $byNumber = $free->first(fn (PassiveInvoice $passive): bool => (string) $passive->invoice_number === (string) $document->invoice_number);

        if ($byNumber !== null) {
            return ['document' => $byNumber, 'kind' => 'exact'];
        }

        $tolerance = (string) $settings->amount_tolerance;
        $days = (int) $settings->date_tolerance_days;
        $near = $free->first(function (PassiveInvoice $passive) use ($document, $tolerance, $days): bool {
            return strtoupper((string) $passive->currency) === strtoupper((string) $document->currency)
                && ConsolidationMath::cmp(ConsolidationMath::abs(ConsolidationMath::sub(ConsolidationMath::norm((string) $document->total_gross), ConsolidationMath::norm((string) $passive->total_amount))), $tolerance) <= 0
                && CarbonImmutable::parse($document->date)->diffInDays(CarbonImmutable::parse($passive->date), true) <= $days;
        });

        return ['document' => $near, 'kind' => $near !== null ? 'tolerance' : null];
    }

    private function newItem(ConsolidationGroup $group, ReconciliationBatch $batch, string $a, string $b, SalesInvoice $document): ReconciliationItem
    {
        return ReconciliationItem::withoutGlobalScopes()->create([
            'tenant_id' => $group->tenant_id,
            'batch_id' => $batch->id,
            'group_id' => $group->id,
            'entity_a_tenant_id' => $a,
            'entity_b_tenant_id' => $b,
            'a_document_id' => $document->id,
            'a_document_number' => $document->invoice_number,
            'a_document_date' => CarbonImmutable::parse($document->date)->toDateString(),
            'a_currency' => strtoupper((string) $document->currency),
            'a_amount' => ConsolidationMath::norm((string) $document->total_gross),
            'a_tax' => ConsolidationMath::norm((string) $document->total_tax),
            'status' => MatchStatus::Unmatched->value,
            'anomaly_type' => AnomalyType::None->value,
        ]);
    }

    private function orphan(ConsolidationGroup $group, ReconciliationBatch $batch, string $a, string $b, PassiveInvoice $passive): void
    {
        ReconciliationItem::withoutGlobalScopes()->create([
            'tenant_id' => $group->tenant_id,
            'batch_id' => $batch->id,
            'group_id' => $group->id,
            'entity_a_tenant_id' => $a,
            'entity_b_tenant_id' => $b,
            'b_document_id' => $passive->id,
            'b_document_number' => $passive->invoice_number,
            'b_document_date' => CarbonImmutable::parse($passive->date)->toDateString(),
            'b_currency' => strtoupper((string) $passive->currency),
            'b_amount' => ConsolidationMath::norm((string) $passive->total_amount),
            'b_tax' => ConsolidationMath::norm((string) $passive->total_tax),
            'difference' => ConsolidationMath::neg(ConsolidationMath::norm((string) $passive->total_amount)),
            'status' => MatchStatus::Unmatched->value,
            'anomaly_type' => AnomalyType::MissingCounterpart->value,
            'anomaly_reason' => 'missing_in_issuer',
        ]);
    }

    private function fillCounterpart(ReconciliationItem $item, PassiveInvoice $b): void
    {
        $item->forceFill([
            'b_document_id' => $b->id,
            'b_document_number' => $b->invoice_number,
            'b_document_date' => CarbonImmutable::parse($b->date)->toDateString(),
            'b_currency' => strtoupper((string) $b->currency),
            'b_amount' => ConsolidationMath::norm((string) $b->total_amount),
            'b_tax' => ConsolidationMath::norm((string) $b->total_tax),
        ]);
    }

    /**
     * @return array{type: AnomalyType, reason: ?string, difference: string, difference_group: string}
     */
    private function classify(ConsolidationGroup $group, GroupSettings $settings, ReconciliationItem $item, CarbonImmutable $asOf, ?PassiveInvoice $b): array
    {
        $tolerance = ConsolidationMath::norm((string) $settings->amount_tolerance);
        $groupCurrency = (string) $group->group_currency;
        $a = ConsolidationMath::norm((string) $item->a_amount);
        $aCurrency = (string) $item->a_currency;

        if ($b === null) {
            $date = CarbonImmutable::parse((string) $item->a_document_date);
            $recent = $date->diffInDays($asOf, true) <= (int) $settings->date_tolerance_days;

            return ['type' => $recent ? AnomalyType::Timing : AnomalyType::MissingCounterpart, 'reason' => $recent ? 'in_transit' : 'missing_in_counterparty', 'difference' => $a, 'difference_group' => $this->toGroup($a, $aCurrency, $groupCurrency, $asOf)];
        }

        $bAmount = ConsolidationMath::norm((string) $b->total_amount);
        $bCurrency = strtoupper((string) $b->currency);
        $aGroup = $this->toGroup($a, $aCurrency, $groupCurrency, $asOf);
        $bGroup = $this->toGroup($bAmount, $bCurrency, $groupCurrency, $asOf);
        $differenceGroup = ConsolidationMath::sub($aGroup, $bGroup);

        if ($aCurrency !== $bCurrency) {
            $nativeDifference = ConsolidationMath::sub($a, $bAmount);

            return ['type' => AnomalyType::Fx, 'reason' => 'currency_mismatch', 'difference' => $nativeDifference, 'difference_group' => $differenceGroup];
        }

        $difference = ConsolidationMath::sub($a, $bAmount);
        $aNet = ConsolidationMath::sub($a, ConsolidationMath::norm((string) $item->a_tax));
        $bNet = ConsolidationMath::norm((string) $b->total_net);
        $aRate = (string) (SalesInvoice::withoutGlobalScopes()->whereKey($item->a_document_id)->value('exchange_rate') ?? '1');
        $bRate = (string) ($b->exchange_rate ?? '1');
        $rateGap = bccomp($aRate, '0', 8) > 0 ? ConsolidationMath::abs(bcsub(bcdiv($bRate, $aRate, 8), '1', 8)) : '0';

        $type = AnomalyType::None;
        $reason = null;

        if (ConsolidationMath::cmp(ConsolidationMath::abs($difference), $tolerance) > 0) {
            $netDifference = ConsolidationMath::abs(ConsolidationMath::sub($aNet, $bNet));
            $taxOnly = ConsolidationMath::cmp($netDifference, $tolerance) <= 0;
            $type = $taxOnly ? AnomalyType::Tax : AnomalyType::Amount;
            $reason = $taxOnly ? 'tax_difference' : 'amount_difference';
        } elseif (bccomp($rateGap, '0.001', 8) > 0 && ConsolidationMath::cmp(ConsolidationMath::abs($differenceGroup), $tolerance) > 0) {
            $type = AnomalyType::Fx;
            $reason = 'rate_difference';
        } elseif ($b->gl_journal_entry_id === null) {
            $type = AnomalyType::Timing;
            $reason = 'mirror_draft';
        } elseif (CarbonImmutable::parse((string) $item->a_document_date)->diffInDays(CarbonImmutable::parse($b->date), true) > (int) $settings->date_tolerance_days) {
            $type = AnomalyType::Timing;
            $reason = 'booking_date_gap';
        } elseif ($this->settlement($item) !== (string) ($b->payment_status ?? 'unpaid')) {
            $type = AnomalyType::Timing;
            $reason = 'payment_lag';
        }

        return ['type' => $type, 'reason' => $reason, 'difference' => $difference, 'difference_group' => $differenceGroup];
    }

    private function settlement(ReconciliationItem $item): string
    {
        return (string) (SalesInvoice::withoutGlobalScopes()->whereKey($item->a_document_id)->value('payment_status') ?? 'unpaid');
    }

    private function resolve(User $user, ConsolidationGroup $group, ReconciliationItem $item, MatchStatus $status, string $reason, ?string $adjustment = null): ReconciliationItem
    {
        $this->scope->assertReadable($user, $group);

        return $this->scope->runAs((string) $group->tenant_id, function () use ($user, $item, $status, $reason, $adjustment): ReconciliationItem {
            $this->assertOpen($item);
            $item->forceFill([
                'status' => $status->value,
                'resolution_reason' => $reason,
                'adjustment_amount' => $adjustment,
                'resolved_by' => $user->id,
                'resolved_at' => now(),
            ])->save();
            $this->refreshTotals($item);

            return $item->refresh();
        });
    }

    private function assertOpen(ReconciliationItem $item): void
    {
        if (in_array($item->status, [MatchStatus::WrittenOff->value, MatchStatus::Adjusted->value], true)) {
            throw new IntercompanyConflictException('The item is already closed.', 'item_closed');
        }
    }

    private function refreshTotals(ReconciliationItem $item): void
    {
        $batch = ReconciliationBatch::withoutGlobalScopes()->find($item->batch_id);
        $batch?->forceFill(['totals' => $this->totalsOf($batch)])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function totalsOf(ReconciliationBatch $batch): array
    {
        $items = ReconciliationItem::withoutGlobalScopes()->where('batch_id', $batch->id)->get();
        $byStatus = array_fill_keys(MatchStatus::codes(), 0);
        $byAnomaly = array_fill_keys(['timing', 'fx', 'amount', 'tax', 'missing_counterpart'], ConsolidationMath::zero());

        foreach ($items as $item) {
            $byStatus[$item->status]++;

            if (isset($byAnomaly[$item->anomaly_type]) && ! in_array($item->status, [MatchStatus::WrittenOff->value, MatchStatus::Adjusted->value], true)) {
                $byAnomaly[$item->anomaly_type] = ConsolidationMath::add($byAnomaly[$item->anomaly_type], ConsolidationMath::abs((string) $item->difference_group_currency));
            }
        }

        return [
            'items' => $items->count(),
            'matched' => $byStatus['matched'],
            'unmatched' => $byStatus['unmatched'],
            'disputed' => $byStatus['disputed'],
            'written_off' => $byStatus['written_off'],
            'adjusted' => $byStatus['adjusted'],
            'difference_by_anomaly' => $byAnomaly,
        ];
    }

    private function supplierContact(ConsolidationGroup $group, string $entity, string $counterparty): ?string
    {
        $link = PartyLink::withoutGlobalScopes()->where('group_id', $group->id)->where('entity_tenant_id', $entity)->where('counterparty_tenant_id', $counterparty)->where('role', 'supplier')->first();

        return $link === null ? null : (string) $link->contact_id;
    }

    /**
     * @return array{group: string, count: int}
     */
    private function openReceivable(string $tenantId, string $contactId, CarbonImmutable $asOf, string $groupCurrency): array
    {
        $total = ConsolidationMath::zero();
        $count = 0;

        $documents = SalesInvoice::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('contact_id', $contactId)->where('status', 'posted')->where('invoice_type', '!=', 'proforma')->whereDate('date', '<=', $asOf->toDateString())->get();

        foreach ($documents as $document) {
            $open = ConsolidationMath::sub(ConsolidationMath::norm((string) $document->total_gross), ConsolidationMath::norm((string) $document->total_paid));
            $open = $document->invoice_type === 'credit_note' ? ConsolidationMath::neg($open) : $open;

            if (! ConsolidationMath::isZero($open)) {
                $count++;
                $total = ConsolidationMath::add($total, $this->toGroup($open, strtoupper((string) $document->currency), $groupCurrency, $asOf));
            }
        }

        return ['group' => $total, 'count' => $count];
    }

    /**
     * @return array{group: string, count: int}
     */
    private function openPayable(string $tenantId, string $supplierId, CarbonImmutable $asOf, string $groupCurrency): array
    {
        $total = ConsolidationMath::zero();
        $count = 0;

        foreach (PassiveInvoice::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('supplier_id', $supplierId)->whereDate('date', '<=', $asOf->toDateString())->get() as $document) {
            $open = ConsolidationMath::sub(ConsolidationMath::norm((string) $document->total_amount), ConsolidationMath::norm((string) $document->total_paid));
            $open = $document->document_type === 'TD04' ? ConsolidationMath::neg($open) : $open;

            if (! ConsolidationMath::isZero($open)) {
                $count++;
                $total = ConsolidationMath::add($total, $this->toGroup($open, strtoupper((string) $document->currency), $groupCurrency, $asOf));
            }
        }

        return ['group' => $total, 'count' => $count];
    }

    private function toGroup(string $amount, string $currency, string $groupCurrency, CarbonImmutable $date): string
    {
        if ($currency === $groupCurrency) {
            return ConsolidationMath::norm($amount);
        }

        try {
            return ConsolidationMath::mul($amount, $this->translator->rateOn($currency, $groupCurrency, $date));
        } catch (ConsolidationException $exception) {
            throw new IntercompanyException($exception->getMessage(), 'rate_missing');
        }
    }
}
