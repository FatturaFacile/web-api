<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\DTOs\Accounting\LedgerEntry;

/**
 * Running totals of ledger entries, so the totals of a period can be taken while its entries stream by.
 */
final class LedgerTotals
{
    private int $entries = 0;

    private int $lines = 0;

    private int $debit = 0;

    private int $credit = 0;

    /**
     * @var list<string>
     */
    private array $unbalanced = [];

    public function add(LedgerEntry $entry): void
    {
        $this->entries++;
        $this->lines += count($entry->lines);
        $this->debit += $entry->totalDebit();
        $this->credit += $entry->totalCredit();

        if (! $entry->isBalanced()) {
            $this->unbalanced[] = $entry->number;
        }
    }

    /**
     * @return array{entries: int, lines: int, total_debit: float, total_credit: float, balanced: bool, unbalanced_entries: array<int, string>}
     */
    public function toArray(): array
    {
        return [
            'entries' => $this->entries,
            'lines' => $this->lines,
            'total_debit' => $this->debit / 100,
            'total_credit' => $this->credit / 100,
            'balanced' => $this->debit === $this->credit,
            'unbalanced_entries' => $this->unbalanced,
        ];
    }
}
