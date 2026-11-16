<?php

declare(strict_types=1);

namespace Modules\LeaseAccounting\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\LeaseAccounting\Models\LeaseContract;
use Modules\LeaseAccounting\Models\LeasePeriodPosting;
use Modules\LeaseAccounting\Models\LeaseScheduleLine;
use Modules\LeaseAccounting\Models\LeaseScheduleVersion;
use Modules\LeaseAccounting\Support\LeaseCalculator;
use Modules\LeaseAccounting\Support\LeaseMoney;
use Modules\LeaseAccounting\Support\LeaseTerms;

/**
 * Measurement of a lease under IFRS 16 / OIC / Swiss GAAP FER (lessee side).
 *
 * - Initial measurement: lease liability = present value of the payments not yet made, discounted at the incremental
 *   borrowing rate with the effective-rate method; right-of-use asset = liability + initial direct costs - incentives.
 * - Remeasurement (modification, index change, reassessment of the term): the liability is measured again from the
 *   effective date on the remaining payments and the difference adjusts the right-of-use asset; a reduction beyond the
 *   carrying amount of the asset is a gain. A modification and a reassessment use a revised discount rate, an index
 *   change keeps the original one. Each measurement is a new schedule version; the rows before the effective date stay.
 * - A short-term or low-value lease with the exemption is expensed and has no measurement.
 */
final readonly class LeaseMeasurementService
{
    public function __construct(
        private LeaseContractService $contracts,
        private LeaseJournalPoster $poster,
    ) {}

    /**
     * The measurement a draft would get on activation, without saving anything.
     *
     * @return array{discount_rate_percent: string, payment_amount: string, lease_liability: string, rou_asset: string, total_undiscounted: string, end_period_number: int, lines: list<array<string, mixed>>}
     */
    public function preview(LeaseContract $contract): array
    {
        $this->assertMeasurable($contract);

        return $this->initialMeasurement($contract);
    }

    /**
     * Activates a draft: measures the lease, writes version 1 and its schedule and, unless told otherwise, posts the
     * recognition journal at the commencement date. A lease with an exemption becomes active without a schedule.
     *
     * @throws ValidationException when the lease is not a draft, cannot be measured or the ledger refuses the entry
     */
    public function activate(LeaseContract $contract, bool $postRecognition, ?string $actorId): LeaseContract
    {
        return DB::transaction(function () use ($contract, $postRecognition, $actorId): LeaseContract {
            $locked = LeaseContract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $this->contracts->assertTransition($locked, 'active');

            if (! $locked->isExempt()) {
                $this->assertMeasurable($locked);
                $measurement = $this->initialMeasurement($locked);
                $version = LeaseScheduleVersion::create([
                    'tenant_id' => $locked->tenant_id,
                    'lease_contract_id' => $locked->id,
                    'version_number' => 1,
                    'reason' => 'initial',
                    'effective_date' => $locked->commencement_date->toDateString(),
                    'start_period_number' => 1,
                    'end_period_number' => $measurement['end_period_number'],
                    'discount_rate_percent' => $measurement['discount_rate_percent'],
                    'payment_amount' => $measurement['payment_amount'],
                    'liability_before' => '0.00',
                    'liability_after' => $measurement['lease_liability'],
                    'rou_before' => '0.00',
                    'rou_after' => $measurement['rou_asset'],
                    'liability_adjustment' => $measurement['lease_liability'],
                    'rou_adjustment' => $measurement['rou_asset'],
                    'result_amount' => '0.00',
                    'total_undiscounted' => $measurement['total_undiscounted'],
                    'status' => 'current',
                    'created_by' => $actorId,
                ]);

                $this->insertLines($version, $measurement['lines']);

                if ($postRecognition) {
                    $entry = $this->poster->postRecognition($locked, $measurement['lease_liability'], $measurement['rou_asset'], $actorId);
                    $version->update(['journal_entry_id' => (string) $entry->id]);
                }

                $locked->current_version_number = 1;
            }

            $locked->status = 'active';
            $locked->activated_at = now();
            $locked->activated_by = $actorId;
            $locked->save();

            return $locked->refresh();
        });
    }

    /**
     * Measures the lease again from a period start on and writes the next schedule version.
     *
     * @param  array{effective_date: string, reason: string, new_payment_amount?: string|null, remaining_term_months?: int|null, new_discount_rate_percent?: string|null, post_journal?: bool, notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function remeasure(LeaseContract $contract, array $data, ?string $actorId): LeaseScheduleVersion
    {
        return DB::transaction(function () use ($contract, $data, $actorId): LeaseScheduleVersion {
            $locked = LeaseContract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'active' || $locked->isExempt()) {
                throw ValidationException::withMessages(['status' => [__($locked->isExempt() ? 'lease_errors.contract.exempt_not_measured' : 'lease_errors.contract.not_active', ['status' => $locked->status])]]);
            }

            $reason = (string) $data['reason'];
            $current = LeaseScheduleVersion::query()->where('lease_contract_id', $locked->id)->where('status', 'current')->orderByDesc('version_number')->firstOrFail();
            $lines = $locked->currentLines()->get()->keyBy('period_number');
            $start = $this->lineStartingOn($lines->all(), (string) $data['effective_date']);
            $lastPosted = (int) LeasePeriodPosting::query()->where('lease_contract_id', $locked->id)->max('period_number');

            if ($start->period_number <= $lastPosted) {
                throw ValidationException::withMessages(['effective_date' => [__('lease_errors.remeasure.period_already_posted', ['period' => $lastPosted, 'next' => $lastPosted + 1])]]);
            }

            $firstPeriod = $start->period_number;
            $oldEnd = (int) $lines->keys()->max();
            $remaining = $this->remainingTerm($data, $reason, $oldEnd - $firstPeriod + 1);
            $lastPeriod = $firstPeriod - 1 + $remaining;

            if ($lastPeriod % $locked->paymentFrequencyMonths() !== 0) {
                throw ValidationException::withMessages(['remaining_term_months' => [__('lease_errors.remeasure.term_multiple', ['frequency' => $locked->paymentFrequencyMonths()])]]);
            }

            if ($lastPeriod > (int) config('leases.max_term_months')) {
                throw ValidationException::withMessages(['remaining_term_months' => [__('lease_errors.contract.term_too_long', ['max' => config('leases.max_term_months')])]]);
            }

            $rate = $this->discountRate($data, $reason, $current);
            $payment = $this->paymentFor($locked, $current, $data, $reason, $firstPeriod);
            $terms = $this->terms($locked, $payment);
            $plan = LeaseCalculator::paymentPlan($terms, $firstPeriod, $lastPeriod);
            $liability = LeaseCalculator::presentValue($plan, $rate);
            $liabilityBefore = (string) $start->opening_liability;
            $rouBefore = (string) $start->rou_opening;
            $adjustment = LeaseMoney::round(LeaseMoney::sub($liability, $liabilityBefore));
            [$rouAfter, $rouAdjustment, $result] = $this->applyAdjustment($adjustment, $rouBefore);
            $depreciationMonths = LeaseCalculator::depreciationMonths($firstPeriod, $lastPeriod, $locked->useful_life_months, $locked->purchase_option_reasonably_certain);
            $newLines = LeaseCalculator::buildLines($plan, $locked->commencement(), $rate, $liability, $rouAfter, $depreciationMonths);

            LeaseScheduleLine::query()->where('lease_contract_id', $locked->id)->where('period_number', '>=', $firstPeriod)->where('status', '!=', 'superseded')->update(['status' => 'superseded']);
            $current->update(['status' => 'superseded']);

            $version = LeaseScheduleVersion::create([
                'tenant_id' => $locked->tenant_id,
                'lease_contract_id' => $locked->id,
                'version_number' => $current->version_number + 1,
                'reason' => $reason,
                'effective_date' => $start->period_start->toDateString(),
                'start_period_number' => $firstPeriod,
                'end_period_number' => $lastPeriod,
                'discount_rate_percent' => $rate,
                'payment_amount' => $payment,
                'liability_before' => $liabilityBefore,
                'liability_after' => $liability,
                'rou_before' => $rouBefore,
                'rou_after' => $rouAfter,
                'liability_adjustment' => $adjustment,
                'rou_adjustment' => $rouAdjustment,
                'result_amount' => $result,
                'total_undiscounted' => LeaseCalculator::totalPayments($plan),
                'status' => 'current',
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
            ]);

            $this->insertLines($version, $newLines);

            if (($data['post_journal'] ?? true) === true) {
                $entry = $this->poster->postRemeasurement($locked, $start->period_start->toDateString(), (string) $version->id, (int) $version->version_number, $adjustment, $rouAdjustment, $result, $actorId);

                if ($entry !== null) {
                    $version->update(['journal_entry_id' => (string) $entry->id]);
                }
            }

            $locked->update(['current_version_number' => $version->version_number]);

            return $version->refresh();
        });
    }

    /**
     * @throws ValidationException when the lease has an exemption (it is expensed, not measured) or the asset would be negative
     */
    private function assertMeasurable(LeaseContract $contract): void
    {
        if ($contract->isExempt()) {
            throw ValidationException::withMessages(['exemption' => [__('lease_errors.contract.exempt_not_measured')]]);
        }

        $this->contracts->assertConsistent(collect(LeaseContractService::termFields())->mapWithKeys(fn (string $field): array => [$field => $contract->getAttribute($field)])->all());
    }

    /**
     * @return array{discount_rate_percent: string, payment_amount: string, lease_liability: string, rou_asset: string, total_undiscounted: string, end_period_number: int, lines: list<array<string, mixed>>}
     */
    private function initialMeasurement(LeaseContract $contract): array
    {
        $end = $contract->effectiveTermMonths();
        $rate = LeaseMoney::normalize((string) $contract->incremental_borrowing_rate_percent, 6);
        $terms = $this->terms($contract, LeaseMoney::normalize((string) $contract->payment_amount));
        $plan = LeaseCalculator::paymentPlan($terms, 1, $end);
        $liability = LeaseCalculator::presentValue($plan, $rate);
        $rou = LeaseMoney::round(LeaseMoney::sub(LeaseMoney::add($liability, (string) $contract->initial_direct_costs), (string) $contract->incentives_received));

        if (! LeaseMoney::isPositive($rou)) {
            throw ValidationException::withMessages(['incentives_received' => [__('lease_errors.contract.negative_rou')]]);
        }

        $depreciationMonths = LeaseCalculator::depreciationMonths(1, $end, $contract->useful_life_months, $contract->purchase_option_reasonably_certain);

        return [
            'discount_rate_percent' => $rate,
            'payment_amount' => $terms->paymentAmount,
            'lease_liability' => $liability,
            'rou_asset' => $rou,
            'total_undiscounted' => LeaseCalculator::totalPayments($plan),
            'end_period_number' => $end,
            'lines' => LeaseCalculator::buildLines($plan, $contract->commencement(), $rate, $liability, $rou, $depreciationMonths),
        ];
    }

    /**
     * The cash flows of a version: a fixed escalation is part of the measurement, an index-linked one is not (it is
     * measured when the index changes); the purchase option price is paid with the last period when it is reasonably certain.
     */
    private function terms(LeaseContract $contract, string $payment): LeaseTerms
    {
        return new LeaseTerms(
            paymentAmount: $payment,
            frequencyMonths: $contract->paymentFrequencyMonths(),
            timing: $contract->payment_timing,
            escalationRatePercent: $contract->escalation_type === 'fixed' ? LeaseMoney::normalize((string) $contract->escalation_rate_percent, 6) : '0',
            purchaseOptionPrice: $contract->purchase_option_reasonably_certain && $contract->purchase_option_price !== null ? LeaseMoney::normalize((string) $contract->purchase_option_price) : null,
        );
    }

    /**
     * @param  array<int, LeaseScheduleLine>  $lines
     *
     * @throws ValidationException when no row of the schedule starts on the date
     */
    private function lineStartingOn(array $lines, string $date): LeaseScheduleLine
    {
        foreach ($lines as $line) {
            if ($line->period_start->toDateString() === CarbonImmutable::parse($date)->toDateString()) {
                return $line;
            }
        }

        throw ValidationException::withMessages(['effective_date' => [__('lease_errors.remeasure.effective_not_period_start')]]);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function remainingTerm(array $data, string $reason, int $currentRemaining): int
    {
        $requested = isset($data['remaining_term_months']) ? (int) $data['remaining_term_months'] : $currentRemaining;

        if ($reason === 'index_change' && $requested !== $currentRemaining) {
            throw ValidationException::withMessages(['remaining_term_months' => [__('lease_errors.remeasure.term_fixed_for_index')]]);
        }

        if ($requested < 1) {
            throw ValidationException::withMessages(['remaining_term_months' => [__('lease_errors.remeasure.term_zero')]]);
        }

        return $requested;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function discountRate(array $data, string $reason, LeaseScheduleVersion $current): string
    {
        $revised = $data['new_discount_rate_percent'] ?? null;

        if ($reason === 'index_change') {
            if ($revised !== null) {
                throw ValidationException::withMessages(['new_discount_rate_percent' => [__('lease_errors.remeasure.rate_not_allowed')]]);
            }

            return LeaseMoney::normalize((string) $current->discount_rate_percent, 6);
        }

        if ($revised === null) {
            throw ValidationException::withMessages(['new_discount_rate_percent' => [__('lease_errors.remeasure.rate_required')]]);
        }

        return LeaseMoney::normalize((string) $revised, 6);
    }

    /**
     * The payment in force at the effective period: the one the caller states, or the one the schedule in force would
     * have asked for (an index change must state it).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function paymentFor(LeaseContract $contract, LeaseScheduleVersion $current, array $data, string $reason, int $firstPeriod): string
    {
        $stated = $data['new_payment_amount'] ?? null;

        if ($stated !== null) {
            return LeaseMoney::normalize((string) $stated);
        }

        if ($reason === 'index_change') {
            throw ValidationException::withMessages(['new_payment_amount' => [__('lease_errors.remeasure.payment_required')]]);
        }

        $previous = $this->terms($contract, LeaseMoney::normalize((string) $current->payment_amount));

        return LeaseCalculator::paymentInForceAt($previous, (int) $current->start_period_number, $firstPeriod);
    }

    /**
     * Splits the change of the liability between the asset and the result: an increase adds to the asset; a decrease
     * reduces it down to zero and the rest is a gain.
     *
     * @return array{0: string, 1: string, 2: string} asset after, change of the asset, result recognised
     */
    private function applyAdjustment(string $adjustment, string $rouBefore): array
    {
        if (LeaseMoney::compare($adjustment, '0') >= 0) {
            return [LeaseMoney::round(LeaseMoney::add($rouBefore, $adjustment)), $adjustment, '0.00'];
        }

        $reduction = LeaseMoney::abs($adjustment);

        if (LeaseMoney::compare($reduction, $rouBefore) <= 0) {
            return [LeaseMoney::round(LeaseMoney::sub($rouBefore, $reduction)), $adjustment, '0.00'];
        }

        return ['0.00', LeaseMoney::round(LeaseMoney::sub('0', $rouBefore)), LeaseMoney::round(LeaseMoney::sub($reduction, $rouBefore))];
    }

    /**
     * Bulk insert of the rows of a version: a schedule has up to hundreds of rows, which would otherwise write as many
     * audit entries.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function insertLines(LeaseScheduleVersion $version, array $lines): void
    {
        $now = now();
        $rows = array_map(static fn (array $line): array => $line + [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $version->tenant_id,
            'lease_contract_id' => $version->lease_contract_id,
            'schedule_version_id' => $version->id,
            'status' => 'scheduled',
            'created_at' => $now,
            'updated_at' => $now,
        ], $lines);

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('lease_schedule_lines')->insert($chunk);
        }
    }
}
