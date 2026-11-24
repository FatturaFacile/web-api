<?php

declare(strict_types=1);

namespace App\DTOs\Accounting;

use Carbon\CarbonImmutable;

/**
 * A balanced double-entry posting: the debits of its lines equal their credits. The number is assigned when the
 * entries of a period are put in order.
 */
final class LedgerEntry
{
    public string $number = '';

    /**
     * @param  array<int, LedgerLine>  $lines
     */
    public function __construct(
        public readonly CarbonImmutable $date,
        public readonly string $source,
        public readonly string $sortKey,
        public readonly string $documentType,
        public readonly string $documentNumber,
        public readonly ?CarbonImmutable $documentDate,
        public readonly ?string $counterpartName,
        public readonly ?string $taxId,
        public readonly string $currency,
        public readonly array $lines,
    ) {}

    public function totalDebit(): int
    {
        return array_sum(array_map(fn (LedgerLine $line): int => $line->debit, $this->lines));
    }

    public function totalCredit(): int
    {
        return array_sum(array_map(fn (LedgerLine $line): int => $line->credit, $this->lines));
    }

    public function isBalanced(): bool
    {
        return $this->totalDebit() === $this->totalCredit();
    }
}
