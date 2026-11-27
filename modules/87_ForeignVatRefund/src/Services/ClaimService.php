<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\ForeignVatRefund\Domain\Enums\ClaimStatus;
use Modules\ForeignVatRefund\Domain\Enums\LedgerStatus;
use Modules\ForeignVatRefund\Domain\Enums\PeriodType;
use Modules\ForeignVatRefund\Domain\Exceptions\ForeignVatException;
use Modules\ForeignVatRefund\Models\ForeignVatLedgerEntry;
use Modules\ForeignVatRefund\Models\VatRefundClaim;
use Modules\ForeignVatRefund\Models\VatRefundClaimEvent;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;

/**
 * Builds the refund claims (dossiers) from the eligible ledger lines and drives their lifecycle:
 * DRAFT -> READY_TO_SUBMIT -> SUBMITTED (locks the lines) -> ADDITIONAL_INFO_REQUESTED -> APPROVED / PARTIALLY_APPROVED / REJECTED -> REFUNDED.
 * A refusal and the bank receipt are booked in the General Ledger (see ForeignVatPoster).
 */
final class ClaimService
{
    public function __construct(
        private readonly RefundRuleResolver $rules,
        private readonly ThresholdMonitor $monitor,
        private readonly ForeignVatPoster $poster,
    ) {}

    /**
     * What a claim for the country and period would contain, and what still blocks it. Changes nothing.
     *
     * @return array<string, mixed>
     */
    public function preview(string $tenantId, string $country, int $fiscalYear, PeriodType $type, ?int $quarter = null, ?array $ledgerIds = null): array
    {
        $country = strtoupper($country);
        $home = $this->rules->homeCountry($tenantId);
        $procedure = $this->rules->procedure($tenantId, $home, $country);
        $blockers = [];

        if ($procedure === null) {
            return ['target_country_code' => $country, 'home_country_code' => $home, 'blockers' => [$home === $country ? 'domestic_vat' : 'no_procedure'], 'items' => [], 'items_count' => 0];
        }

        if (! $this->rules->hasReciprocity($tenantId, $home, $country)) {
            $blockers[] = 'no_reciprocity';
        }

        [$start, $end] = $this->period($type, $fiscalYear, $quarter);
        $items = $this->eligibleItems($tenantId, $country, $procedure->currency_code, $start, $end, $ledgerIds);
        $total = $items->reduce(fn (string $sum, ForeignVatLedgerEntry $entry): string => Decimal::add($sum, (string) $entry->recoverable_vat_amount), '0');
        $carried = $items->reduce(fn (string $sum, ForeignVatLedgerEntry $entry): string => Decimal::add($sum, (string) $entry->base_recoverable_vat_amount), '0');
        $threshold = $type === PeriodType::Annual ? $procedure->min_annual_amount : $procedure->min_quarterly_amount;
        $deadline = $this->monitor->deadlineOf($procedure, $fiscalYear);
        $days = (int) CarbonImmutable::today()->diffInDays($deadline, false);

        if ($items->isEmpty()) {
            $blockers[] = 'no_items';
        }

        if ($type === PeriodType::Quarterly && $procedure->min_quarterly_amount === null) {
            $blockers[] = 'period_not_allowed';
        } elseif ($threshold !== null && Decimal::compare($total, (string) $threshold) < 0) {
            $blockers[] = 'below_threshold';
        }

        if ($days < 0) {
            $blockers[] = 'deadline_expired';
        }

        $representative = $this->monitor->mandateStatus($tenantId, $country, CarbonImmutable::today(), $procedure->requires_fiscal_representative, $procedure->requires_taxable_status_certificate);

        return [
            'home_country_code' => $home,
            'target_country_code' => $country,
            'regime_type' => $procedure->regime_type->value,
            'currency_code' => $procedure->currency_code,
            'period_type' => $type->value,
            'fiscal_year' => $fiscalYear,
            'period_start_date' => $start->format('Y-m-d'),
            'period_end_date' => $end->format('Y-m-d'),
            'statutory_deadline_date' => $deadline->format('Y-m-d'),
            'days_remaining' => $days,
            'items_count' => $items->count(),
            'items' => $items->map(fn (ForeignVatLedgerEntry $entry): array => [
                'id' => $entry->id, 'document_number' => $entry->document_number, 'document_date' => $entry->document_date->format('Y-m-d'),
                'supplier_name' => $entry->supplier_name, 'expense_category_code' => $entry->expense_category_code,
                'recoverable_vat_amount' => (string) $entry->recoverable_vat_amount, 'has_attachment' => $entry->attachment_path !== null,
            ])->values()->all(),
            'total_requested_amount' => Decimal::round2($total),
            'carried_base_amount' => Decimal::round2($carried),
            'threshold' => $threshold === null ? null : (string) $threshold,
            'requires_fiscal_representative' => $procedure->requires_fiscal_representative,
            'requires_taxable_status_certificate' => $procedure->requires_taxable_status_certificate,
            'representative' => $representative,
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  array{target_country_code: string, fiscal_year: int, period_type: string, quarter?: int|null, ledger_ids?: list<string>|null, notes?: string|null}  $data
     */
    public function create(string $tenantId, array $data, ?string $userId): VatRefundClaim
    {
        $type = PeriodType::from($data['period_type']);
        $preview = $this->preview($tenantId, $data['target_country_code'], (int) $data['fiscal_year'], $type, $data['quarter'] ?? null, $data['ledger_ids'] ?? null);

        foreach (['domestic_vat', 'no_procedure', 'no_reciprocity', 'period_not_allowed', 'no_items', 'below_threshold', 'deadline_expired'] as $blocker) {
            if (in_array($blocker, $preview['blockers'], true)) {
                throw ForeignVatException::because($blocker, ['country' => $data['target_country_code'], 'threshold' => $preview['threshold'] ?? null]);
            }
        }

        return DB::transaction(function () use ($tenantId, $data, $preview, $type, $userId): VatRefundClaim {
            $ids = array_column($preview['items'], 'id');
            $locked = ForeignVatLedgerEntry::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereIn('id', $ids)->lockForUpdate()->get();

            if ($locked->contains(fn (ForeignVatLedgerEntry $entry): bool => $entry->vat_refund_claim_id !== null || $entry->status !== LedgerStatus::EligibleAccumulating)) {
                throw ForeignVatException::because('ledger_entry_locked');
            }

            $claim = VatRefundClaim::query()->create([
                'tenant_id' => $tenantId,
                'claim_number' => $this->nextNumber($tenantId, (int) $data['fiscal_year'], $preview['target_country_code']),
                'home_country_code' => $preview['home_country_code'],
                'target_country_code' => $preview['target_country_code'],
                'regime_type' => $preview['regime_type'],
                'period_type' => $type->value,
                'fiscal_year' => $data['fiscal_year'],
                'period_start_date' => $preview['period_start_date'],
                'period_end_date' => $preview['period_end_date'],
                'statutory_deadline_date' => $preview['statutory_deadline_date'],
                'currency_code' => $preview['currency_code'],
                'total_requested_amount' => $preview['total_requested_amount'],
                'carried_base_amount' => $preview['carried_base_amount'],
                'status' => ClaimStatus::Draft->value,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            ForeignVatLedgerEntry::withoutGlobalScopes()->whereIn('id', $ids)->update(['vat_refund_claim_id' => $claim->id, 'status' => LedgerStatus::IncludedInClaim->value]);
            $this->log($claim, 'created', null, ClaimStatus::Draft, null, ['items' => count($ids)], $userId);

            return $claim->refresh();
        });
    }

    public function markReady(VatRefundClaim $claim, ?string $userId): VatRefundClaim
    {
        $this->assertTransition($claim, ClaimStatus::ReadyToSubmit);
        $tenantId = (string) $claim->tenant_id;
        $procedure = $this->rules->procedure($tenantId, $claim->home_country_code, $claim->target_country_code) ?? throw ForeignVatException::because('no_procedure');

        if (CarbonImmutable::today()->greaterThan($claim->statutory_deadline_date)) {
            throw ForeignVatException::because('deadline_expired', ['deadline' => $claim->statutory_deadline_date->format('Y-m-d')]);
        }

        $representative = $this->monitor->mandateStatus($tenantId, $claim->target_country_code, CarbonImmutable::today(), $procedure->requires_fiscal_representative, $procedure->requires_taxable_status_certificate);

        match ($representative['status']) {
            'MISSING' => throw ForeignVatException::because('fiscal_representative_missing', ['country' => $claim->target_country_code]),
            'CERTIFICATE_MISSING' => throw ForeignVatException::because('certificate_missing'),
            'CERTIFICATE_EXPIRED' => throw ForeignVatException::because('certificate_expired', ['expiry' => $representative['certificate_expiry_date']]),
            default => null,
        };

        if (! $claim->regime_type->usesElectronicPortal()) {
            $without = $claim->items()->whereNull('attachment_path')->count();

            if ($without > 0) {
                throw ForeignVatException::because('attachments_missing', ['count' => $without]);
            }
        }

        return DB::transaction(function () use ($claim, $representative, $userId): VatRefundClaim {
            $claim->update(['status' => ClaimStatus::ReadyToSubmit->value, 'fiscal_representative_mandate_id' => $representative['mandate_id']]);
            $this->log($claim, 'status_changed', ClaimStatus::Draft, ClaimStatus::ReadyToSubmit, null, [], $userId);

            return $claim->refresh();
        });
    }

    public function backToDraft(VatRefundClaim $claim, ?string $userId): VatRefundClaim
    {
        $this->assertTransition($claim, ClaimStatus::Draft);
        $claim->update(['status' => ClaimStatus::Draft->value]);
        $this->log($claim, 'status_changed', ClaimStatus::ReadyToSubmit, ClaimStatus::Draft, null, [], $userId);

        return $claim->refresh();
    }

    /**
     * Records the filing with the foreign authority: from this moment the lines of the claim are locked. The peremptory deadline applies
     * to the first filing only; the answer to a request for information has none.
     */
    public function submit(VatRefundClaim $claim, ?string $protocolNumber, ?string $submittedOn, ?string $userId): VatRefundClaim
    {
        $this->assertTransition($claim, ClaimStatus::Submitted);
        $from = $claim->status;
        $date = CarbonImmutable::parse($submittedOn ?? now()->toDateString())->startOfDay();

        if ($from === ClaimStatus::ReadyToSubmit && $date->greaterThan($claim->statutory_deadline_date)) {
            throw ForeignVatException::because('deadline_expired', ['deadline' => $claim->statutory_deadline_date->format('Y-m-d')]);
        }

        return DB::transaction(function () use ($claim, $from, $date, $protocolNumber, $userId): VatRefundClaim {
            $claim->update([
                'status' => ClaimStatus::Submitted->value,
                'submission_date' => $claim->submission_date ?? $date,
                'protocol_number' => $protocolNumber ?? $claim->protocol_number,
            ]);
            $this->log($claim, 'status_changed', $from, ClaimStatus::Submitted, null, ['protocol_number' => $protocolNumber], $userId);

            return $claim->refresh();
        });
    }

    public function requestInformation(VatRefundClaim $claim, string $note, ?string $userId): VatRefundClaim
    {
        $this->assertTransition($claim, ClaimStatus::AdditionalInfoRequested);
        $from = $claim->status;
        $claim->update(['status' => ClaimStatus::AdditionalInfoRequested->value]);
        $this->log($claim, 'status_changed', $from, ClaimStatus::AdditionalInfoRequested, $note, [], $userId);

        return $claim->refresh();
    }

    /**
     * The decision of the authority. A refused part is reclassified at once from the transitory receivable to the non recoverable expense.
     */
    public function decide(VatRefundClaim $claim, string $approvedAmount, ?string $note, ?string $userId): VatRefundClaim
    {
        $requested = (string) $claim->total_requested_amount;
        $approved = Decimal::round2($approvedAmount);

        if (Decimal::isNegative($approved) || Decimal::compare($approved, $requested) > 0) {
            throw ForeignVatException::because('approved_amount_invalid', ['requested' => $requested]);
        }

        $target = Decimal::compare($approved, $requested) === 0 ? ClaimStatus::Approved : (Decimal::isZero($approved) ? ClaimStatus::Rejected : ClaimStatus::PartiallyApproved);
        $this->assertTransition($claim, $target);

        return DB::transaction(function () use ($claim, $requested, $approved, $target, $note, $userId): VatRefundClaim {
            $from = $claim->status;
            $rejected = Decimal::round2(Decimal::sub($requested, $approved));
            $rejectedBase = $this->shareOfCarried($claim, $rejected);
            $journal = $this->poster->postRejection($claim, $rejectedBase, now()->toDateString(), $userId);

            $claim->update([
                'status' => $target->value,
                'total_approved_amount' => $approved,
                'total_rejected_amount' => $rejected,
                'rejection_journal_entry_id' => $journal?->id,
            ]);
            $this->markItems($claim, $target === ClaimStatus::Rejected ? LedgerStatus::NonRecoverableExpensed : ($target === ClaimStatus::PartiallyApproved ? LedgerStatus::PartiallyRejected : null));
            $this->log($claim, 'status_changed', $from, $target, $note, ['approved' => $approved, 'rejected' => $rejected], $userId);

            return $claim->refresh();
        });
    }

    /**
     * The refund arrived on the bank: debit the bank (at the exchange rate of the receipt), clear the receivable, reclassify a refusal that was
     * not booked yet and book the exchange difference.
     *
     * @param  array{refunded_amount: string, bank_account_id: string, exchange_rate?: string|null, received_on?: string|null, note?: string|null}  $data
     */
    public function reconcile(VatRefundClaim $claim, array $data, ?string $userId): VatRefundClaim
    {
        $this->assertTransition($claim, ClaimStatus::Refunded);
        $tenantId = (string) $claim->tenant_id;
        $requested = (string) $claim->total_requested_amount;
        $refunded = Decimal::round2($data['refunded_amount']);
        $alreadyRejected = (string) $claim->total_rejected_amount;

        if (! Decimal::isPositive($refunded)) {
            throw ForeignVatException::because('refund_amount_required');
        }

        if (Decimal::compare($refunded, Decimal::sub($requested, $alreadyRejected)) > 0) {
            throw ForeignVatException::because('refund_exceeds_approved', ['approved' => Decimal::sub($requested, $alreadyRejected)]);
        }

        $accounting = TenantAccountingCurrency::of($tenantId);
        $rate = $claim->currency_code === $accounting ? '1' : (string) ($data['exchange_rate'] ?? '');

        if ($rate === '' || ! Decimal::isPositive($rate)) {
            throw ForeignVatException::because('exchange_rate_required', ['currency' => $claim->currency_code, 'base' => $accounting]);
        }

        return DB::transaction(function () use ($claim, $data, $requested, $refunded, $alreadyRejected, $rate, $userId): VatRefundClaim {
            $from = $claim->status;
            $date = (string) ($data['received_on'] ?? now()->toDateString());
            $totalRejected = Decimal::round2(Decimal::sub($requested, $refunded));
            $newRejected = Decimal::round2(Decimal::sub($totalRejected, $alreadyRejected));
            $carriedLeft = Decimal::sub((string) $claim->carried_base_amount, $this->shareOfCarried($claim, $alreadyRejected));
            $newRejectedBase = Decimal::isPositive($newRejected) ? $this->shareOfCarried($claim, $newRejected) : '0.00';
            $bankBase = Decimal::round2(Decimal::mul($refunded, $rate));
            $journal = $this->poster->postReconciliation($claim, (string) $data['bank_account_id'], $bankBase, $newRejectedBase, Decimal::round2($carriedLeft), $date, $userId);

            $claim->update([
                'status' => ClaimStatus::Refunded->value,
                'total_approved_amount' => $refunded,
                'total_rejected_amount' => $totalRejected,
                'total_refunded_amount' => $refunded,
                'refund_exchange_rate' => $rate,
                'bank_account_id' => $data['bank_account_id'],
                'reconciliation_journal_entry_id' => $journal->id,
                'refunded_on' => $date,
            ]);
            $this->markItems($claim, Decimal::isPositive($totalRejected) ? LedgerStatus::PartiallyRejected : LedgerStatus::Refunded);
            $this->log($claim, 'refund_reconciled', $from, ClaimStatus::Refunded, $data['note'] ?? null, ['refunded' => $refunded, 'rejected' => $totalRejected, 'journal_entry_id' => $journal->id], $userId);

            return $claim->refresh();
        });
    }

    /**
     * A claim not filed yet can be deleted: its lines go back to the pool of eligible VAT.
     */
    public function delete(VatRefundClaim $claim, ?string $userId): void
    {
        if ($claim->status->locksItems()) {
            throw ForeignVatException::because('claim_locked');
        }

        DB::transaction(function () use ($claim, $userId): void {
            ForeignVatLedgerEntry::withoutGlobalScopes()->where('vat_refund_claim_id', $claim->id)->update(['vat_refund_claim_id' => null, 'status' => LedgerStatus::EligibleAccumulating->value]);
            $this->log($claim, 'deleted', $claim->status, null, null, [], $userId);
            $claim->delete();
        });
    }

    public function log(VatRefundClaim $claim, string $event, ?ClaimStatus $from, ?ClaimStatus $to, ?string $note, array $payload, ?string $userId): void
    {
        VatRefundClaimEvent::query()->create([
            'tenant_id' => $claim->tenant_id,
            'vat_refund_claim_id' => $claim->id,
            'event' => $event,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'note' => $note,
            'payload' => $payload === [] ? null : $payload,
            'user_id' => $userId,
        ]);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function period(PeriodType $type, int $fiscalYear, ?int $quarter): array
    {
        if ($type === PeriodType::Annual) {
            return [CarbonImmutable::create($fiscalYear, 1, 1)->startOfDay(), CarbonImmutable::create($fiscalYear, 12, 31)->startOfDay()];
        }

        if ($quarter === null || $quarter < 1 || $quarter > 4) {
            throw ForeignVatException::because('quarter_required');
        }

        $start = CarbonImmutable::create($fiscalYear, ($quarter - 1) * 3 + 1, 1)->startOfDay();

        return [$start, $start->addMonths(2)->endOfMonth()->startOfDay()];
    }

    /**
     * @param  list<string>|null  $ledgerIds
     * @return Collection<int, ForeignVatLedgerEntry>
     */
    private function eligibleItems(string $tenantId, string $country, string $currency, CarbonImmutable $start, CarbonImmutable $end, ?array $ledgerIds): Collection
    {
        return ForeignVatLedgerEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', LedgerStatus::EligibleAccumulating->value)
            ->whereNull('vat_refund_claim_id')
            ->whereNull('deleted_at')
            ->where('supplier_country_code', $country)
            ->where('currency_code', $currency)
            ->whereDate('document_date', '>=', $start->format('Y-m-d'))
            ->whereDate('document_date', '<=', $end->format('Y-m-d'))
            ->when($ledgerIds !== null && $ledgerIds !== [], fn ($query) => $query->whereIn('id', $ledgerIds))
            ->orderBy('document_date')
            ->get();
    }

    /**
     * The part of the amount carried on the receivable (in the accounting currency) that corresponds to an amount of the claim currency.
     */
    private function shareOfCarried(VatRefundClaim $claim, string $amount): string
    {
        $requested = (string) $claim->total_requested_amount;

        if (! Decimal::isPositive($requested) || ! Decimal::isPositive($amount)) {
            return '0.00';
        }

        return Decimal::round2(Decimal::div(Decimal::mul((string) $claim->carried_base_amount, $amount), $requested));
    }

    private function markItems(VatRefundClaim $claim, ?LedgerStatus $status): void
    {
        if ($status !== null) {
            ForeignVatLedgerEntry::withoutGlobalScopes()->where('vat_refund_claim_id', $claim->id)->update(['status' => $status->value]);
        }
    }

    private function assertTransition(VatRefundClaim $claim, ClaimStatus $target): void
    {
        if (! $claim->status->canTransitionTo($target)) {
            throw ForeignVatException::because('invalid_transition', ['from' => $claim->status->value, 'to' => $target->value]);
        }
    }

    private function nextNumber(string $tenantId, int $year, string $country): string
    {
        $count = VatRefundClaim::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('fiscal_year', $year)->where('target_country_code', $country)->count();

        return sprintf('FVR-%d-%s-%04d', $year, $country, $count + 1);
    }
}
