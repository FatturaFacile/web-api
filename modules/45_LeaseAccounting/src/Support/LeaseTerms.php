<?php

declare(strict_types=1);

namespace Modules\LeaseAccounting\Support;

/**
 * The cash flows of one version of a lease schedule: the payment in force at the first period, how often it is paid,
 * whether at the start or at the end of the period, the fixed annual escalation that is already known, and the
 * purchase option price when exercising it is reasonably certain.
 */
final readonly class LeaseTerms
{
    /**
     * @param  string  $paymentAmount  payment in force at the first period of the version (two decimals)
     * @param  int  $frequencyMonths  months between two payments: 1, 3, 6 or 12
     * @param  string  $timing  `advance` (start of the period) or `arrears` (end of the period)
     * @param  string  $escalationRatePercent  fixed annual escalation; `0` for flat and for index-linked payments, whose
     *                                         future indexation is not known and is remeasured when the index changes
     * @param  string|null  $purchaseOptionPrice  paid with the last period when the option is reasonably certain
     */
    public function __construct(
        public string $paymentAmount,
        public int $frequencyMonths,
        public string $timing,
        public string $escalationRatePercent = '0',
        public ?string $purchaseOptionPrice = null,
    ) {}
}
