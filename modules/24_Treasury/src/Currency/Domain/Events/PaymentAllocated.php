<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Events;

use Modules\Treasury\Currency\Domain\Enums\FxDirection;

/**
 * A receipt or a payment was allocated to an open item (accounts receivable or payable). Amounts are decimal strings.
 * `ledgerPostedDifference` tells that the settlement entry already carries the realised exchange difference (as the General
 * Ledger payment posting does), so the Currency module records it for reporting without posting it a second time.
 */
final readonly class PaymentAllocated
{
    public function __construct(
        public string $tenantId,
        public string $allocationId,
        public FxDirection $direction,
        public string $documentCurrency,
        public string $settledAmount,
        public string $documentRate,
        public string $paymentDate,
        public ?string $paymentRate = null,
        public ?string $paymentFunctionalAmount = null,
        public ?string $itemAmount = null,
        public ?string $documentReference = null,
        public ?string $partnerId = null,
        public bool $ledgerPostedDifference = false,
        public ?string $actorId = null,
    ) {}
}
