<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Services;

use App\Models\Expense;
use Modules\TravelExpense\Models\TravelMileageClaim;
use Modules\TravelExpense\Models\TravelPerDiemDay;
use Modules\TravelExpense\Models\TravelTrip;
use Modules\TravelExpense\Models\TravelTripExpense;
use Modules\TravelExpense\Support\Money;

/**
 * Settles a trip: what the company owes the employee (per-diem days, mileage claims and the approved reimbursable
 * expenses linked to the trip) against the advance already paid. A positive balance is owed to the employee, a negative
 * one is owed back by the employee, zero means settled.
 */
final class ReconciliationService
{
    public const string OwedToEmployee = 'owed_to_employee';

    public const string OwedByEmployee = 'owed_by_employee';

    public const string Settled = 'settled';

    /**
     * The live figures of a trip. Expenses count when they are approved and reimbursable; those still in draft or
     * submitted block the reconciliation, rejected ones are shown but never counted.
     *
     * @return array{
     *     currency: string,
     *     per_diem_total: string,
     *     per_diem_taxable_total: string,
     *     mileage_total: string,
     *     expenses_total: string,
     *     total_due: string,
     *     advance_amount: string,
     *     advance_paid: string,
     *     balance: string,
     *     balance_amount: string,
     *     direction: string,
     *     pending_expenses: int,
     *     rejected_expenses: int,
     *     expenses: list<array{id: string, expense_number: string, date: string, merchant_name: string|null, amount: string, currency: string, status: string, counted: bool}>
     * }
     */
    public function summarize(TravelTrip $trip): array
    {
        $perDiem = '0.00';
        $taxable = '0.00';

        foreach (TravelPerDiemDay::query()->where('trip_id', $trip->id)->get() as $day) {
            $perDiem = Money::add($perDiem, (string) $day->amount);
            $taxable = Money::add($taxable, (string) $day->taxable_amount);
        }

        $mileage = '0.00';

        foreach (TravelMileageClaim::query()->where('trip_id', $trip->id)->get() as $claim) {
            $mileage = Money::add($mileage, (string) $claim->amount);
        }

        $expenseTotal = '0.00';
        $pending = 0;
        $rejected = 0;
        $expenses = [];
        $expenseIds = TravelTripExpense::query()->where('trip_id', $trip->id)->pluck('expense_id')->all();

        foreach (Expense::query()->whereIn('id', $expenseIds)->orderBy('date')->get() as $expense) {
            $counted = $expense->status === 'approved' && $expense->is_reimbursable && $expense->currency === $trip->currency;

            if ($counted) {
                $expenseTotal = Money::add($expenseTotal, (string) $expense->amount_gross);
            } elseif ($expense->status === 'rejected') {
                $rejected++;
            } elseif ($expense->status !== 'approved') {
                $pending++;
            }

            $expenses[] = [
                'id' => (string) $expense->id,
                'expense_number' => (string) $expense->expense_number,
                'date' => $expense->date->toDateString(),
                'merchant_name' => $expense->merchant_name,
                'amount' => (string) $expense->amount_gross,
                'currency' => (string) $expense->currency,
                'status' => (string) $expense->status,
                'counted' => $counted,
            ];
        }

        $totalDue = Money::add(Money::add($perDiem, $mileage), $expenseTotal);
        $advancePaid = $trip->advance_paid_at !== null ? Money::normalize((string) $trip->advance_amount) : '0.00';
        $balance = Money::sub($totalDue, $advancePaid);

        return [
            'currency' => $trip->currency,
            'per_diem_total' => $perDiem,
            'per_diem_taxable_total' => $taxable,
            'mileage_total' => $mileage,
            'expenses_total' => $expenseTotal,
            'total_due' => $totalDue,
            'advance_amount' => Money::normalize((string) $trip->advance_amount),
            'advance_paid' => $advancePaid,
            'balance' => $balance,
            'balance_amount' => Money::abs($balance),
            'direction' => match (Money::compare($balance, '0')) {
                1 => self::OwedToEmployee,
                -1 => self::OwedByEmployee,
                default => self::Settled,
            },
            'pending_expenses' => $pending,
            'rejected_expenses' => $rejected,
            'expenses' => $expenses,
        ];
    }
}
