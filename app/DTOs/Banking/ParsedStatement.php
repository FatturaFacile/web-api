<?php

declare(strict_types=1);

namespace App\DTOs\Banking;

use Carbon\CarbonImmutable;

/**
 * A bank statement as read from a file: the header (account, date, balances, currency, source format) and its lines.
 * Balances are null for formats that do not carry them (CAMT.054 notifications).
 */
final readonly class ParsedStatement
{
    public const FORMAT_CBI = 'cbi';

    public const FORMAT_CAMT053 = 'camt053';

    public const FORMAT_CAMT054 = 'camt054';

    /**
     * @param  array<int, ParsedStatementLine>  $lines
     */
    public function __construct(
        public string $sourceFormat,
        public string $accountIban,
        public CarbonImmutable $statementDate,
        public ?float $openingBalance,
        public ?float $closingBalance,
        public string $currency,
        public array $lines,
    ) {}

    public function totalEntries(): int
    {
        return count($this->lines);
    }
}
