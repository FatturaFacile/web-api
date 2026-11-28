<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Services;

use Illuminate\Support\Facades\DB;
use Modules\ExpenseManagement\Domain\Enums\PaymentMethod;
use Modules\ExpenseManagement\Domain\Enums\ReconciliationStatus;
use Modules\ExpenseManagement\Exceptions\ExpenseException;
use Modules\ExpenseManagement\Models\CorporateCardAccount;
use Modules\ExpenseManagement\Models\CorporateCardTransaction;
use Modules\ExpenseManagement\Models\ExpenseLine;
use Modules\ExpenseManagement\Support\Decimal;

/**
 * Corporate card statement lines: import, match with expense lines (one-to-one, or split over several lines), unmatch,
 * ignore and dispute. A line paid by the card is not reimbursed to the employee.
 */
final class CardReconciliationService
{
    public function __construct(private readonly ExpenseLineService $lines, private readonly ReportService $reports) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<CorporateCardTransaction>
     */
    public function import(CorporateCardAccount $account, array $rows, string $tenantId): array
    {
        $imported = [];

        foreach ($rows as $row) {
            $externalId = isset($row['external_id']) ? (string) $row['external_id'] : null;
            $existing = $externalId === null ? null : CorporateCardTransaction::query()->where('card_account_id', $account->id)->where('external_id', $externalId)->first();

            $imported[] = $existing ?? CorporateCardTransaction::create([
                'tenant_id' => $tenantId,
                'card_account_id' => $account->id,
                'external_id' => $externalId,
                'merchant_name' => (string) $row['merchant_name'],
                'transaction_date' => $row['transaction_date'],
                'posting_date' => $row['posting_date'] ?? null,
                'amount' => Decimal::normalize($row['amount']),
                'currency' => strtoupper((string) ($row['currency'] ?? $account->currency)),
                'status' => ReconciliationStatus::Unmatched,
            ])->refresh();
        }

        return $imported;
    }

    /**
     * @param  list<array{expense_line_id: string, amount?: string|null}>  $matches
     */
    public function match(CorporateCardTransaction $transaction, array $matches, string $userId): CorporateCardTransaction
    {
        if ($matches === []) {
            throw new ExpenseException('card_transaction_mismatch');
        }

        if ($transaction->status === ReconciliationStatus::Ignored || $transaction->status === ReconciliationStatus::Disputed) {
            throw new ExpenseException('invalid_transition', 409);
        }

        return DB::transaction(function () use ($transaction, $matches, $userId): CorporateCardTransaction {
            $allocated = (string) ExpenseLine::query()->where('corporate_card_transaction_id', $transaction->id)->sum('amount_in_base_currency');

            foreach ($matches as $match) {
                $line = ExpenseLine::query()->with('report', 'category')->findOrFail($match['expense_line_id']);
                $report = $line->report;

                if ($line->corporate_card_transaction_id !== null && $line->corporate_card_transaction_id !== $transaction->id) {
                    throw new ExpenseException('card_transaction_already_matched');
                }

                if ($report->currency !== $transaction->currency) {
                    throw new ExpenseException('card_transaction_mismatch');
                }

                $this->reports->assertEditable($report);

                if ($line->corporate_card_transaction_id !== $transaction->id) {
                    $allocated = Decimal::add($allocated, isset($match['amount']) ? Decimal::normalize($match['amount']) : $line->amount_in_base_currency);
                }

                if (Decimal::cmp($allocated, $transaction->amount) > 0) {
                    throw new ExpenseException('amount_exceeds_transaction', 422, ['amount' => $transaction->amount]);
                }

                $line->fill([
                    'payment_method' => PaymentMethod::CorporateCard,
                    'corporate_card_transaction_id' => $transaction->id,
                    'is_reimbursable' => false,
                    'is_traceable_payment' => true,
                ]);
                $this->lines->reassess($report, $line);
                $this->reports->recalculate($report);
                $this->reports->audit($report, 'card_matched', $userId, null, null, ['transaction_id' => $transaction->id], (string) $line->id);
            }

            return $this->refresh($transaction);
        });
    }

    public function unmatch(CorporateCardTransaction $transaction, string $userId): CorporateCardTransaction
    {
        return DB::transaction(function () use ($transaction, $userId): CorporateCardTransaction {
            foreach (ExpenseLine::query()->with('report', 'category')->where('corporate_card_transaction_id', $transaction->id)->get() as $line) {
                $report = $line->report;
                $this->reports->assertEditable($report);
                $line->fill(['payment_method' => PaymentMethod::EmployeeOutOfPocket, 'corporate_card_transaction_id' => null, 'is_reimbursable' => true]);
                $this->lines->reassess($report, $line);
                $this->reports->recalculate($report);
                $this->reports->audit($report, 'card_unmatched', $userId, null, null, ['transaction_id' => $transaction->id], (string) $line->id);
            }

            return $this->refresh($transaction);
        });
    }

    public function mark(CorporateCardTransaction $transaction, ReconciliationStatus $status): CorporateCardTransaction
    {
        if (ExpenseLine::query()->where('corporate_card_transaction_id', $transaction->id)->exists()) {
            throw new ExpenseException('card_transaction_already_matched');
        }

        $transaction->update(['status' => $status, 'matched_amount' => '0.00']);

        return $transaction;
    }

    private function refresh(CorporateCardTransaction $transaction): CorporateCardTransaction
    {
        $matched = Decimal::normalize((string) ExpenseLine::query()->where('corporate_card_transaction_id', $transaction->id)->sum('amount_in_base_currency'));
        $covered = Decimal::isPositive($matched) && abs((float) Decimal::sub($transaction->amount, $matched)) <= 0.01;
        $transaction->update([
            'matched_amount' => $matched,
            'status' => $covered ? ReconciliationStatus::Matched : ReconciliationStatus::Unmatched,
        ]);

        return $transaction;
    }
}
