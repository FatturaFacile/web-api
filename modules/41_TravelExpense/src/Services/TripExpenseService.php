<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Services;

use App\Models\Expense;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\TravelExpense\Models\TravelTrip;
use Modules\TravelExpense\Models\TravelTripExpense;

/**
 * Links the expenses of the expenses feature that settle a trip. The expense stays where it is (its own approval,
 * attachments and accounting export); the trip only references it by id.
 *
 * An expense can be linked when it is the employee's own, reimbursable, in the currency of the trip, not rejected, not
 * already linked to a trip and dated from two weeks before the departure (pre-paid tickets) to two months after the
 * return.
 */
final readonly class TripExpenseService
{
    public const int DaysBeforeDeparture = 14;

    public const int DaysAfterReturn = 60;

    public function __construct(private TravelLedger $ledger) {}

    public function link(TravelTrip $trip, string $expenseId): TravelTripExpense
    {
        if (! $trip->isWorking()) {
            throw ValidationException::withMessages(['status' => __('travel_errors.trip.not_editable')]);
        }

        $expense = Expense::query()->find($expenseId);

        if ($expense === null) {
            throw ValidationException::withMessages(['expense_id' => __('travel_errors.expense.not_found')]);
        }

        $this->assertLinkable($trip, $expense);

        $link = TravelTripExpense::create(['tenant_id' => $trip->tenant_id, 'trip_id' => $trip->id, 'expense_id' => $expense->id]);
        $this->ledger->record('TRAVEL_EXPENSE_LINKED', $trip, ['expense_id' => $expense->id, 'amount' => (string) $expense->amount_gross, 'currency' => $expense->currency]);

        return $link;
    }

    public function unlink(TravelTrip $trip, string $expenseId): void
    {
        if (! $trip->isWorking()) {
            throw ValidationException::withMessages(['status' => __('travel_errors.trip.not_editable')]);
        }

        $link = TravelTripExpense::query()->where('trip_id', $trip->id)->where('expense_id', $expenseId)->firstOrFail();
        $link->forceDelete();
        $this->ledger->record('TRAVEL_EXPENSE_UNLINKED', $trip, ['expense_id' => $expenseId]);
    }

    private function assertLinkable(TravelTrip $trip, Expense $expense): void
    {
        if ((string) $expense->user_id !== (string) $trip->user_id) {
            throw ValidationException::withMessages(['expense_id' => __('travel_errors.expense.other_employee')]);
        }

        if (! $expense->is_reimbursable) {
            throw ValidationException::withMessages(['expense_id' => __('travel_errors.expense.not_reimbursable')]);
        }

        if ($expense->status === 'rejected') {
            throw ValidationException::withMessages(['expense_id' => __('travel_errors.expense.rejected')]);
        }

        if ($expense->currency !== $trip->currency) {
            throw ValidationException::withMessages(['expense_id' => __('travel_errors.expense.currency_mismatch', ['expense' => $expense->currency, 'trip' => $trip->currency])]);
        }

        $date = CarbonImmutable::parse($expense->date->toDateString());

        if ($date->lessThan(CarbonImmutable::parse($trip->start_date->toDateString())->subDays(self::DaysBeforeDeparture))
            || $date->greaterThan(CarbonImmutable::parse($trip->end_date->toDateString())->addDays(self::DaysAfterReturn))) {
            throw ValidationException::withMessages(['expense_id' => __('travel_errors.expense.date_outside_trip')]);
        }

        if (TravelTripExpense::query()->where('expense_id', $expense->id)->exists()) {
            throw ValidationException::withMessages(['expense_id' => __('travel_errors.expense.already_linked')]);
        }
    }
}
