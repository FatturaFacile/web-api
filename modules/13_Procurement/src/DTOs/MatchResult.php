<?php

declare(strict_types=1);

namespace Modules\Procurement\DTOs;

use Modules\Procurement\Enums\InvoiceMatchStatus;
use Modules\Procurement\Enums\InvoiceMatchType;

/**
 * The outcome of matching an invoice, before it is stored: the status, the totals and the line results.
 */
final readonly class MatchResult
{
    /**
     * @param  list<MatchLineResult>  $lines
     * @param  list<string>  $reasons  MatchDiscrepancyReason values
     * @param  list<array<string, string|null>>  $toleranceSnapshot
     */
    public function __construct(
        public InvoiceMatchType $type,
        public InvoiceMatchStatus $status,
        public array $lines,
        public array $reasons,
        public string $orderedTotal,
        public string $receivedTotal,
        public string $invoicedTotal,
        public string $varianceAmount,
        public string $variancePercent,
        public string $grIrAmount,
        public string $priceVarianceAmount,
        public int $discrepancyCount,
        public string $currency,
        public array $toleranceSnapshot = [],
    ) {}
}
