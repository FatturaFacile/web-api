<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Services;

use App\Models\Tenant;
use App\Services\Tenancy\TenantClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\TravelExpense\Models\TravelPerDiemDay;
use Modules\TravelExpense\Models\TravelTrip;
use Modules\TravelExpense\Support\Money;

/**
 * Life of a business trip: draft, submitted, approved or rejected, completed and reconciled.
 *
 * - draft: the employee prepares the request (purpose, dates, estimated budget, advance); it is the only status in
 *   which the request itself changes. A rejected request returns to draft to be corrected.
 * - submitted: waiting for the approver; the employee can withdraw it. Submitting checks that the trip does not overlap
 *   another active trip of the employee and that the advance does not exceed the estimated budget.
 * - approved: the approver (never the employee, except the owner of the company) accepts the trip, optionally changing
 *   the advance. Itinerary, mileage, per diem and expenses are worked on from here.
 * - completed: the employee is back (the trip has started).
 * - reconciled: finance settles the advance against what is due; the figures are frozen on the trip.
 */
final readonly class TripService
{
    public function __construct(
        private TravelNumbers $numbers,
        private TravelLedger $ledger,
        private ReconciliationService $reconciliation,
        private PerDiemService $perDiem,
        private RateService $rates,
        private TenantClock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data, string $userId): TravelTrip
    {
        $tenant = Tenant::query()->find($tenantId);
        $jurisdiction = (string) ($data['rate_jurisdiction'] ?? (in_array($tenant?->country, TravelTrip::Jurisdictions, true) ? $tenant->country : 'IT'));
        $currency = (string) ($data['currency'] ?? ($jurisdiction === 'CH' ? 'CHF' : 'EUR'));

        $attributes = $this->requestAttributes($data) + [
            'rate_jurisdiction' => $jurisdiction,
            'currency' => $currency,
            'estimated_budget' => '0.00',
            'advance_amount' => '0.00',
        ];
        $this->assertAdvanceWithinBudget($attributes);

        return DB::transaction(function () use ($tenantId, $attributes, $userId): TravelTrip {
            $trip = TravelTrip::create($attributes + [
                'tenant_id' => $tenantId,
                'number' => $this->numbers->next($tenantId),
                'user_id' => $userId,
                'status' => TravelTrip::Draft,
            ]);

            $this->ledger->record('TRAVEL_TRIP_CREATED', $trip, ['status' => $trip->status, 'estimated_budget' => $trip->estimated_budget, 'currency' => $trip->currency]);

            return $trip;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(TravelTrip $trip, array $data): TravelTrip
    {
        $this->assertStatus($trip, [TravelTrip::Draft], 'travel_errors.trip.only_draft_editable');

        $attributes = $this->requestAttributes($data);
        $this->assertAdvanceWithinBudget($attributes + ['estimated_budget' => $trip->estimated_budget, 'advance_amount' => $trip->advance_amount]);
        $trip->update($attributes);

        if ($trip->wasChanged(['start_date', 'end_date', 'departure_time', 'return_time', 'destination_country', 'rate_jurisdiction', 'currency'])) {
            TravelPerDiemDay::query()->where('trip_id', $trip->id)->forceDelete();
        }

        return $trip->refresh();
    }

    public function delete(TravelTrip $trip): void
    {
        $this->assertStatus($trip, [TravelTrip::Draft], 'travel_errors.trip.only_draft_deletable');
        $trip->delete();
    }

    public function submit(TravelTrip $trip): TravelTrip
    {
        $this->assertStatus($trip, [TravelTrip::Draft], 'travel_errors.trip.submit_only_draft');
        $this->assertAdvanceWithinBudget(['estimated_budget' => $trip->estimated_budget, 'advance_amount' => $trip->advance_amount]);

        $overlap = TravelTrip::query()
            ->where('user_id', $trip->user_id)
            ->where('id', '!=', $trip->id)
            ->whereIn('status', [TravelTrip::Submitted, TravelTrip::Approved, TravelTrip::Completed, TravelTrip::Reconciled])
            ->whereDate('start_date', '<=', $trip->end_date->toDateString())
            ->whereDate('end_date', '>=', $trip->start_date->toDateString())
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages(['start_date' => __('travel_errors.trip.overlaps_another')]);
        }

        return $this->transition($trip, [TravelTrip::Draft], TravelTrip::Submitted, 'TRAVEL_TRIP_SUBMITTED', ['submitted_at' => now()]);
    }

    public function withdraw(TravelTrip $trip): TravelTrip
    {
        return $this->transition($trip, [TravelTrip::Submitted], TravelTrip::Draft, 'TRAVEL_TRIP_WITHDRAWN', ['submitted_at' => null]);
    }

    public function approve(TravelTrip $trip, string $approverId, ?string $advanceAmount): TravelTrip
    {
        if ($advanceAmount !== null) {
            $this->assertAdvanceWithinBudget(['estimated_budget' => $trip->estimated_budget, 'advance_amount' => $advanceAmount]);
        }

        return $this->transition($trip, [TravelTrip::Submitted], TravelTrip::Approved, 'TRAVEL_TRIP_APPROVED', array_filter([
            'approved_by' => $approverId,
            'approved_at' => now(),
            'advance_amount' => $advanceAmount === null ? null : Money::normalize($advanceAmount),
        ], static fn (mixed $value): bool => $value !== null));
    }

    public function reject(TravelTrip $trip, string $approverId, string $reason): TravelTrip
    {
        return $this->transition($trip, [TravelTrip::Submitted], TravelTrip::Rejected, 'TRAVEL_TRIP_REJECTED', [
            'rejected_by' => $approverId,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }

    public function reopen(TravelTrip $trip): TravelTrip
    {
        return $this->transition($trip, [TravelTrip::Rejected], TravelTrip::Draft, 'TRAVEL_TRIP_REOPENED', [
            'rejected_by' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
            'submitted_at' => null,
        ]);
    }

    public function complete(TravelTrip $trip): TravelTrip
    {
        if ($trip->status === TravelTrip::Approved && $trip->start_date->toDateString() > $this->clock->today()->toDateString()) {
            throw ValidationException::withMessages(['status' => __('travel_errors.trip.not_started')]);
        }

        return $this->transition($trip, [TravelTrip::Approved], TravelTrip::Completed, 'TRAVEL_TRIP_COMPLETED', ['completed_at' => now()]);
    }

    public function markAdvancePaid(TravelTrip $trip, string $paidAt): TravelTrip
    {
        $this->assertStatus($trip, [TravelTrip::Approved, TravelTrip::Completed], 'travel_errors.advance.not_payable_now');

        if (Money::compare((string) $trip->advance_amount, '0') <= 0) {
            throw ValidationException::withMessages(['advance_amount' => __('travel_errors.advance.none')]);
        }

        if ($trip->advance_paid_at !== null) {
            throw ValidationException::withMessages(['paid_at' => __('travel_errors.advance.already_paid')]);
        }

        $trip->update(['advance_paid_at' => $paidAt]);
        $this->ledger->record('TRAVEL_ADVANCE_PAID', $trip, ['amount' => (string) $trip->advance_amount, 'currency' => $trip->currency]);

        return $trip->refresh();
    }

    /**
     * Settles the advance against what is due and freezes the figures. The per-diem days are calculated now when the
     * trip has none and the company has a rate table for it. Expenses still in draft or submitted block the
     * reconciliation.
     */
    public function reconcile(TravelTrip $trip, string $actorId): TravelTrip
    {
        return DB::transaction(function () use ($trip, $actorId): TravelTrip {
            $locked = TravelTrip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($locked, [TravelTrip::Completed], 'travel_errors.trip.reconcile_only_completed');

            if (! $locked->perDiemDays()->exists() && $this->hasPerDiemRate($locked)) {
                $this->perDiem->calculate($locked);
            }

            $summary = $this->reconciliation->summarize($locked);

            if ($summary['pending_expenses'] > 0) {
                throw ValidationException::withMessages(['expenses' => __('travel_errors.trip.pending_expenses', ['count' => $summary['pending_expenses']])]);
            }

            $locked->update([
                'status' => TravelTrip::Reconciled,
                'reconciled_at' => now(),
                'reconciled_by' => $actorId,
                'reconciled_expenses_total' => $summary['expenses_total'],
                'reconciled_per_diem_total' => $summary['per_diem_total'],
                'reconciled_mileage_total' => $summary['mileage_total'],
                'reconciled_advance_total' => $summary['advance_paid'],
                'reconciled_balance' => $summary['balance'],
                'reconciled_direction' => $summary['direction'],
            ]);
            $this->ledger->record('TRAVEL_TRIP_RECONCILED', $locked, [
                'total_due' => $summary['total_due'],
                'advance_paid' => $summary['advance_paid'],
                'balance' => $summary['balance'],
                'direction' => $summary['direction'],
                'currency' => $locked->currency,
            ]);

            return $locked->refresh();
        });
    }

    private function hasPerDiemRate(TravelTrip $trip): bool
    {
        return $this->rates->findPerDiemRate((string) $trip->tenant_id, $trip->rate_jurisdiction, (int) $trip->start_date->format('Y'), $this->perDiem->scopeOf($trip), $trip->destination_country) !== null;
    }

    /**
     * @param  list<string>  $from
     * @param  array<string, mixed>  $attributes
     */
    private function transition(TravelTrip $trip, array $from, string $to, string $event, array $attributes = []): TravelTrip
    {
        return DB::transaction(function () use ($trip, $from, $to, $event, $attributes): TravelTrip {
            $locked = TravelTrip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($locked, $from, 'travel_errors.trip.invalid_transition', ['from' => $locked->status, 'to' => $to]);

            $previous = $locked->status;
            $locked->update(['status' => $to] + $attributes);
            $this->ledger->record($event, $locked, ['from' => $previous, 'to' => $to]);

            return $locked->refresh();
        });
    }

    /**
     * @param  list<string>  $allowed
     * @param  array<string, string>  $replace
     */
    private function assertStatus(TravelTrip $trip, array $allowed, string $messageKey, array $replace = []): void
    {
        if (! in_array($trip->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => __($messageKey, $replace)]);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function assertAdvanceWithinBudget(array $values): void
    {
        if (Money::compare(Money::normalize($values['advance_amount'] ?? '0'), Money::normalize($values['estimated_budget'] ?? '0')) > 0) {
            throw ValidationException::withMessages(['advance_amount' => __('travel_errors.advance.exceeds_budget')]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function requestAttributes(array $data): array
    {
        $attributes = array_intersect_key($data, array_flip([
            'purpose', 'destination_city', 'destination_country', 'start_date', 'end_date', 'departure_time', 'return_time',
            'currency', 'rate_jurisdiction', 'estimated_budget', 'advance_amount',
        ]));

        foreach (['estimated_budget', 'advance_amount'] as $money) {
            if (isset($attributes[$money])) {
                $attributes[$money] = Money::normalize($attributes[$money]);
            }
        }

        if (isset($attributes['destination_country'])) {
            $attributes['destination_country'] = strtoupper((string) $attributes['destination_country']);
        }

        return $attributes;
    }
}
