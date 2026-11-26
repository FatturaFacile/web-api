<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Infrastructure\Persistence;

use Illuminate\Support\Collection;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\CustomerLedger;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\GeneralLedger\Models\SupplierLedger;
use Modules\Treasury\Currency\Domain\Enums\RevaluationLineType;
use Modules\Treasury\Currency\Domain\Support\Dec;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\CashRegister;

/**
 * The positions in a foreign currency of a company at a day, the base of the exposure dashboard and of the revaluation:
 *
 * - open receivables and payables: the open amount of the customer and supplier ledger items per currency and control account,
 *   with the book value at the historical rate of the line that opened each item (payables carry negative balances);
 * - bank accounts and cash registers linked to a ledger account: the balance in the currency (transaction amounts of the lines
 *   in that currency) and the book value (functional amounts of every line of the account).
 *
 * @phpstan-type Position array{line_type: RevaluationLineType, currency: string, reference: ?string, account_id: ?string, currency_balance: string, book_value: string}
 */
class CurrencyPositionProvider
{
    /**
     * @param  list<string>|null  $currencies  limit to these currencies
     * @return list<Position>
     */
    public function positions(string $tenantId, string $functionalCurrency, string $asOf, ?array $currencies = null): array
    {
        $currencies = $currencies === null ? null : array_map('strtoupper', $currencies);

        return array_values(array_filter(
            [...$this->openItems($tenantId, $functionalCurrency, $asOf, $currencies), ...$this->accounts($tenantId, $functionalCurrency, $asOf, $currencies)],
            static fn (array $position): bool => ! Dec::isZero($position['currency_balance']) || ! Dec::isZero($position['book_value']),
        ));
    }

    /**
     * @param  list<string>|null  $currencies
     * @return list<Position>
     */
    private function openItems(string $tenantId, string $functional, string $asOf, ?array $currencies): array
    {
        $groups = [];

        foreach ([RevaluationLineType::Receivable->value => CustomerLedger::class, RevaluationLineType::Payable->value => SupplierLedger::class] as $side => $ledger) {
            $partnerColumn = $side === RevaluationLineType::Receivable->value ? 'customer_id' : 'supplier_id';
            $items = $ledger::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')
                ->where('currency', '!=', $functional)
                ->when($currencies !== null, static fn ($query) => $query->whereIn('currency', $currencies))
                ->whereIn('status', ['open', 'partially_paid'])
                ->whereDate('posting_date', '<=', $asOf)
                ->get();

            foreach ($items as $item) {
                $open = (string) $item->open_amount;

                if (Dec::isZero($open)) {
                    continue;
                }

                $line = JournalLine::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('journal_entry_id', $item->journal_entry_id)
                    ->where('partner_id', $item->getAttribute($partnerColumn))
                    ->orderBy('line_number')
                    ->first(['account_id', 'exchange_rate']);

                if ($line === null) {
                    continue;
                }

                $key = "{$side}|{$item->currency}|{$line->account_id}";
                $groups[$key] ??= ['side' => $side, 'currency' => (string) $item->currency, 'account_id' => (string) $line->account_id, 'open' => '0', 'book' => '0'];
                $groups[$key]['open'] = Dec::add($groups[$key]['open'], $open);
                $groups[$key]['book'] = Dec::add($groups[$key]['book'], Dec::mul($open, (string) $line->exchange_rate));
            }
        }

        $codes = $this->accountCodes($tenantId, array_column($groups, 'account_id'));
        $positions = [];

        foreach ($groups as $group) {
            $sign = $group['side'] === RevaluationLineType::Payable->value ? '-1' : '1';
            $positions[] = [
                'line_type' => RevaluationLineType::from($group['side']),
                'currency' => $group['currency'],
                'reference' => $codes[$group['account_id']] ?? null,
                'account_id' => $group['account_id'],
                'currency_balance' => Dec::mul($group['open'], $sign),
                'book_value' => Dec::mul(Dec::round($group['book'], 2), $sign),
            ];
        }

        return $positions;
    }

    /**
     * @param  list<string>|null  $currencies
     * @return list<Position>
     */
    private function accounts(string $tenantId, string $functional, string $asOf, ?array $currencies): array
    {
        $positions = [];

        $banks = BankAccount::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('is_active', true)->where('currency', '!=', $functional)->whereNotNull('gl_account_id')
            ->when($currencies !== null, static fn ($query) => $query->whereIn('currency', $currencies))->orderBy('iban')->get();

        foreach ($banks as $bank) {
            $positions[] = $this->accountPosition($tenantId, $functional, $asOf, RevaluationLineType::Bank, (string) $bank->currency, (string) $bank->gl_account_id, (string) $bank->iban);
        }

        $registers = CashRegister::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('is_active', true)->where('currency', '!=', $functional)->whereNotNull('gl_account_id')
            ->when($currencies !== null, static fn ($query) => $query->whereIn('currency', $currencies))->orderBy('code')->get();

        foreach ($registers as $register) {
            $positions[] = $this->accountPosition($tenantId, $functional, $asOf, RevaluationLineType::Cash, (string) $register->currency, (string) $register->gl_account_id, (string) $register->code);
        }

        return $positions;
    }

    /**
     * @return Position
     */
    private function accountPosition(string $tenantId, string $functional, string $asOf, RevaluationLineType $type, string $currency, string $accountId, string $reference): array
    {
        $foreign = '0';
        $book = '0';

        /** @var Collection<int, JournalLine> $lines */
        $lines = JournalLine::withoutGlobalScopes()
            ->join('journal_entries as entry', 'entry.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.tenant_id', $tenantId)
            ->where('journal_lines.account_id', $accountId)
            ->whereIn('entry.status', ['posted', 'reversed'])
            ->whereNull('entry.deleted_at')
            ->whereNull('journal_lines.deleted_at')
            ->whereDate('entry.posting_date', '<=', $asOf)
            ->get(['journal_lines.debit_amount', 'journal_lines.credit_amount', 'journal_lines.currency', 'journal_lines.functional_debit', 'journal_lines.functional_credit', 'journal_lines.amount_in_functional_currency']);

        foreach ($lines as $line) {
            if ($line->currency === $currency) {
                $foreign = Dec::add($foreign, Dec::sub((string) $line->debit_amount, (string) $line->credit_amount));
            }

            $book = Dec::add($book, $this->functionalBalance($line, $functional));
        }

        return [
            'line_type' => $type,
            'currency' => $currency,
            'reference' => $reference,
            'account_id' => $accountId,
            'currency_balance' => Dec::round($foreign, 2),
            'book_value' => Dec::round($book, 2),
        ];
    }

    private function functionalBalance(JournalLine $line, string $functional): string
    {
        if ($line->functional_debit !== null || $line->functional_credit !== null) {
            return Dec::sub((string) ($line->functional_debit ?? '0'), (string) ($line->functional_credit ?? '0'));
        }

        $isDebit = Dec::isPositive((string) $line->debit_amount);
        $booked = (string) ($isDebit ? $line->debit_amount : $line->credit_amount);
        $amount = $line->currency === $functional ? $booked : (string) $line->amount_in_functional_currency;

        return $isDebit ? $amount : Dec::neg($amount);
    }

    /**
     * @param  list<string>  $accountIds
     * @return array<string, string>
     */
    private function accountCodes(string $tenantId, array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        return ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereIn('id', array_unique($accountIds))->pluck('account_code', 'id')->map(static fn ($code): string => (string) $code)->all();
    }
}
