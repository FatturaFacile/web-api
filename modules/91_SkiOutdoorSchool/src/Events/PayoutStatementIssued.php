<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Events;

/**
 * A monthly payout statement was issued to an instructor.
 */
final readonly class PayoutStatementIssued
{
    public function __construct(
        public string $statementId,
        public string $instructorId,
        public string $month,
        public float $netPayable,
    ) {}
}
