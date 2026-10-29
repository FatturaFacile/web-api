<?php

declare(strict_types=1);

namespace App\Actions\Expenses;

use App\Events\Expenses\ExpenseUpdated;
use App\Models\Expense;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateExpenseAction
{
    /**
     * Update an existing expense with pessimistic concurrency control.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(Expense $expense, array $data): Expense
    {
        return DB::transaction(function () use ($expense, $data): Expense {
            /** @var Expense $lockedExpense */
            $lockedExpense = Expense::where('id', $expense->id)->lockForUpdate()->firstOrFail();

            if ($lockedExpense->isExported()) {
                throw ValidationException::withMessages([
                    'expense' => 'This expense has already been exported to the accountant and cannot be modified.',
                ]);
            }

            // Recalculate amounts if amounts or tax rate changed
            $taxRate = isset($data['tax_rate']) ? (float) $data['tax_rate'] : (float) $lockedExpense->tax_rate;

            if (isset($data['amount_gross']) && ! isset($data['amount_net'])) {
                $gross = round((float) $data['amount_gross'], 2);
                $net = $taxRate > 0 ? round($gross / (1 + ($taxRate / 100)), 2) : $gross;
                $taxAmount = round($gross - $net, 2);
                $data['amount_gross'] = $gross;
                $data['amount_net'] = $net;
                $data['tax_amount'] = $taxAmount;
            } elseif (isset($data['amount_net']) && ! isset($data['amount_gross'])) {
                $net = round((float) $data['amount_net'], 2);
                $taxAmount = round($net * ($taxRate / 100), 2);
                $gross = round($net + $taxAmount, 2);
                $data['amount_net'] = $net;
                $data['amount_gross'] = $gross;
                $data['tax_amount'] = $taxAmount;
            }

            $data['updated_by'] = Auth::id();

            $changed = array_keys($data);
            $lockedExpense->update($data);

            event(new ExpenseUpdated($lockedExpense, $changed));

            return $lockedExpense->fresh(['category.translations', 'expenseAttachments', 'contact', 'user']);
        });
    }
}
