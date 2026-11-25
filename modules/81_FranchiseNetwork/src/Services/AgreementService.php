<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\FranchiseNetwork\Domain\Enums\AgreementStatus;
use Modules\FranchiseNetwork\Domain\Support\AgreementRules;
use Modules\FranchiseNetwork\Domain\Support\EntryFeeSchedule;
use Modules\FranchiseNetwork\Events\AgreementRenewalDue;
use Modules\FranchiseNetwork\Exceptions\FranchiseNetworkException;
use Modules\FranchiseNetwork\Models\EntryFeeDeferral;
use Modules\FranchiseNetwork\Models\FranchiseAgreement;

/**
 * Lifecycle of the affiliation agreements: drafting with the L. 129/2004 findings, activation (one active agreement per store, no
 * overlapping exclusivity zone, no blocking finding), the deferral schedule of the entry fee, renewal, termination and the alerts of
 * the franchisor (renewal notice, guarantee about to lapse, agreement expired).
 */
final class AgreementService
{
    /** Findings that stop the agreement from being saved at all. */
    private const array BLOCKING_ON_SAVE = ['end_before_start', 'entry_fee_deferral_exceeds_term'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(array $data, ?FranchiseAgreement $agreement, ?string $userId): FranchiseAgreement
    {
        if ($agreement !== null && in_array($agreement->status, [AgreementStatus::Terminated, AgreementStatus::Expired, AgreementStatus::Renewed], true)) {
            throw new FranchiseNetworkException('agreement_closed', 409);
        }

        $attributes = $this->attributes($data, $agreement);
        $this->assertSaveable([...($agreement === null ? [] : $this->flat($agreement)), ...$attributes]);
        $this->assertZoneAvailable($attributes, $agreement);

        return DB::transaction(function () use ($attributes, $agreement, $userId): FranchiseAgreement {
            if ($agreement === null) {
                $agreement = FranchiseAgreement::query()->create([...$attributes, 'status' => AgreementStatus::Draft->value, 'agreement_number' => $attributes['agreement_number'] ?? $this->nextNumber(), 'created_by' => $userId]);
            } else {
                $agreement->update([...$attributes, 'updated_by' => $userId]);

                if ($agreement->status === AgreementStatus::Active) {
                    $this->buildDeferrals($agreement);
                }
            }

            return $agreement->refresh();
        });
    }

    public function activate(FranchiseAgreement $agreement, ?string $userId): FranchiseAgreement
    {
        if ($agreement->status !== AgreementStatus::Draft) {
            throw new FranchiseNetworkException('agreement_not_draft', 409);
        }

        if (FranchiseAgreement::query()->where('store_id', $agreement->store_id)->where('status', AgreementStatus::Active->value)->exists()) {
            throw new FranchiseNetworkException('store_already_has_active_agreement', 409);
        }

        $blocking = collect($this->findings($agreement))->firstWhere('severity', 'error');

        if ($blocking !== null) {
            throw new FranchiseNetworkException('agreement_has_blocking_findings', 422, ['finding' => $blocking['code']]);
        }

        $this->assertZoneAvailable($agreement->attributesToArray(), $agreement);

        return DB::transaction(function () use ($agreement, $userId): FranchiseAgreement {
            $agreement->update(['status' => AgreementStatus::Active->value, 'signed_on' => $agreement->signed_on ?? $this->today()->toDateString(), 'updated_by' => $userId]);
            $this->buildDeferrals($agreement->refresh());

            return $agreement->refresh();
        });
    }

    public function terminate(FranchiseAgreement $agreement, ?string $userId): FranchiseAgreement
    {
        if ($agreement->status !== AgreementStatus::Active) {
            throw new FranchiseNetworkException('agreement_not_active', 409);
        }

        $agreement->update(['status' => AgreementStatus::Terminated->value, 'updated_by' => $userId]);

        return $agreement->refresh();
    }

    /**
     * Renews an active agreement: the old one is closed as renewed and a new one starts the day after it ends.
     */
    public function renew(FranchiseAgreement $agreement, string $newEndDate, ?string $userId): FranchiseAgreement
    {
        if ($agreement->status !== AgreementStatus::Active) {
            throw new FranchiseNetworkException('agreement_not_active', 409);
        }

        $start = $agreement->end_date->addDay();
        $end = CarbonImmutable::parse($newEndDate)->startOfDay();

        if ($end->lessThanOrEqualTo($start)) {
            throw new FranchiseNetworkException('end_before_start');
        }

        return DB::transaction(function () use ($agreement, $start, $end, $userId): FranchiseAgreement {
            $agreement->update(['status' => AgreementStatus::Renewed->value, 'updated_by' => $userId]);

            $next = FranchiseAgreement::query()->create([
                ...$agreement->only(['store_id', 'formula_id', 'jurisdiction', 'auto_renew', 'renewal_notice_days', 'exclusivity_zone', 'min_purchase_amount', 'min_purchase_source', 'approved_supplier_ids', 'guarantee_type', 'guarantee_amount', 'guarantee_reference', 'guarantee_expires_on', 'payment_method', 'mandate_reference', 'mandate_signed_on', 'currency', 'non_binding_pricing']),
                'agreement_number' => $this->nextNumber(),
                'status' => AgreementStatus::Active->value,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'term_months' => max(1, (int) round($start->diffInMonths($end->addDay()))),
                'signed_on' => $this->today()->toDateString(),
                'entry_fee' => 0,
                'entry_fee_deferral_months' => 0,
                'created_by' => $userId,
            ]);

            return $next->refresh();
        });
    }

    /**
     * Findings of the contract text plus the alerts of the day.
     *
     * @return list<array{code: string, severity: string, params: array<string, scalar>}>
     */
    public function findings(FranchiseAgreement $agreement): array
    {
        return AgreementRules::evaluate($this->flat($agreement), $this->today());
    }

    /**
     * @return list<array{code: string, severity: string, params: array<string, scalar>}>
     */
    public function alerts(FranchiseAgreement $agreement): array
    {
        return AgreementRules::alerts($this->flat($agreement), $this->today());
    }

    /**
     * Active agreements that need attention, with their alerts.
     *
     * @return list<array{agreement: FranchiseAgreement, alerts: list<array{code: string, severity: string, params: array<string, scalar>}>}>
     */
    public function attentionList(): array
    {
        $rows = [];

        foreach (FranchiseAgreement::query()->with('store')->where('status', AgreementStatus::Active->value)->orderBy('end_date')->get() as $agreement) {
            $alerts = $this->alerts($agreement);

            if ($alerts !== []) {
                $rows[] = ['agreement' => $agreement, 'alerts' => $alerts];
            }
        }

        return $rows;
    }

    /**
     * Daily maintenance: auto-renews or expires the agreements past their end date, and sends the renewal reminder once.
     *
     * @return array{renewed: int, expired: int, reminders: int}
     */
    public function maintain(?CarbonImmutable $today = null): array
    {
        $today ??= $this->today();
        $result = ['renewed' => 0, 'expired' => 0, 'reminders' => 0];

        foreach (FranchiseAgreement::query()->where('status', AgreementStatus::Active->value)->get() as $agreement) {
            if ($agreement->end_date->lessThan($today)) {
                if ($agreement->auto_renew) {
                    $this->renew($agreement, $agreement->end_date->addMonthsNoOverflow(max(1, (int) $agreement->term_months))->toDateString(), null);
                    $result['renewed']++;
                } else {
                    $agreement->update(['status' => AgreementStatus::Expired->value]);
                    $result['expired']++;
                }

                continue;
            }

            $due = collect(AgreementRules::alerts($this->flat($agreement), $today))->contains('code', 'renewal_due');

            if ($due && $agreement->renewal_reminder_sent_at === null) {
                $agreement->update(['renewal_reminder_sent_at' => now()]);
                event(new AgreementRenewalDue((string) $agreement->tenant_id, (string) $agreement->id, (string) $agreement->store_id, $agreement->end_date->toDateString()));
                $result['reminders']++;
            }
        }

        return $result;
    }

    /**
     * Recognises as revenue the shares of the deferred entry fees up to the period (inclusive).
     */
    public function recognizeDeferrals(string $period): int
    {
        return EntryFeeDeferral::query()->whereNull('recognized_at')->where('period', '<=', $period)->update(['recognized_at' => now()]);
    }

    private function buildDeferrals(FranchiseAgreement $agreement): void
    {
        EntryFeeDeferral::query()->where('agreement_id', $agreement->id)->whereNull('recognized_at')->forceDelete();
        $recognized = EntryFeeDeferral::query()->where('agreement_id', $agreement->id)->get();

        if ($recognized->isNotEmpty() || (int) $agreement->entry_fee_deferral_months === 0) {
            return;
        }

        foreach (EntryFeeSchedule::build((string) $agreement->entry_fee, (int) $agreement->entry_fee_deferral_months, $agreement->start_date->toImmutable()) as $row) {
            EntryFeeDeferral::query()->create(['agreement_id' => $agreement->id, 'period' => $row['period'], 'amount' => $row['amount']]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?FranchiseAgreement $agreement): array
    {
        $attributes = collect($data)->only([
            'store_id', 'formula_id', 'agreement_number', 'jurisdiction', 'disclosure_delivered_on', 'signed_on', 'start_date', 'end_date', 'auto_renew',
            'renewal_notice_days', 'exclusivity_zone', 'entry_fee', 'entry_fee_deferral_months', 'min_purchase_amount', 'min_purchase_source',
            'approved_supplier_ids', 'guarantee_type', 'guarantee_amount', 'guarantee_reference', 'guarantee_expires_on', 'payment_method',
            'mandate_reference', 'mandate_signed_on', 'currency', 'non_binding_pricing', 'notes',
        ])->all();

        $merged = [...($agreement?->attributesToArray() ?? []), ...$attributes];
        $start = CarbonImmutable::parse((string) $merged['start_date'])->startOfDay();
        $end = CarbonImmutable::parse((string) $merged['end_date'])->startOfDay();
        $attributes['term_months'] = max(0, (int) round($start->diffInMonths($end->addDay())));

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertSaveable(array $attributes): void
    {
        foreach (AgreementRules::evaluate([...$attributes, 'jurisdiction' => $attributes['jurisdiction'] ?? 'IT', 'entry_fee' => $attributes['entry_fee'] ?? 0, 'payment_method' => $attributes['payment_method'] ?? 'bank_transfer', 'guarantee_type' => $attributes['guarantee_type'] ?? 'none'], $this->today()) as $finding) {
            if (in_array($finding['code'], self::BLOCKING_ON_SAVE, true)) {
                throw new FranchiseNetworkException($finding['code']);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertZoneAvailable(array $attributes, ?FranchiseAgreement $current): void
    {
        $zone = $attributes['exclusivity_zone'] ?? $current?->exclusivity_zone;
        $storeId = $attributes['store_id'] ?? $current?->store_id;

        if (empty($zone['postal_codes'])) {
            return;
        }

        $others = FranchiseAgreement::query()->with('store')
            ->whereIn('status', [AgreementStatus::Draft->value, AgreementStatus::Active->value])
            ->where('store_id', '!=', $storeId)
            ->when($current, fn ($query) => $query->where('id', '!=', $current->id))
            ->get();

        foreach ($others as $other) {
            if (AgreementRules::zonesOverlap($zone, $other->exclusivity_zone)) {
                throw new FranchiseNetworkException('exclusivity_zone_overlap', 409, ['store' => (string) $other->store?->code]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function flat(FranchiseAgreement $agreement): array
    {
        return [
            ...$agreement->attributesToArray(),
            'jurisdiction' => $agreement->jurisdiction->value,
            'payment_method' => $agreement->payment_method->value,
            'guarantee_type' => $agreement->guarantee_type->value,
            'start_date' => $agreement->start_date->toDateString(),
            'end_date' => $agreement->end_date->toDateString(),
            'signed_on' => $agreement->signed_on?->toDateString(),
            'disclosure_delivered_on' => $agreement->disclosure_delivered_on?->toDateString(),
            'guarantee_expires_on' => $agreement->guarantee_expires_on?->toDateString(),
        ];
    }

    private function nextNumber(): string
    {
        $year = $this->today()->year;
        $sequence = FranchiseAgreement::query()->withTrashed()->where('agreement_number', 'like', 'AGR-'.$year.'-%')->count() + 1;

        do {
            $number = sprintf('AGR-%d-%05d', $year, $sequence++);
        } while (FranchiseAgreement::query()->withTrashed()->where('agreement_number', $number)->exists());

        return $number;
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::today();
    }
}
