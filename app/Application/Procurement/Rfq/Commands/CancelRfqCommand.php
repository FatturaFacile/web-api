<?php

declare(strict_types=1);

namespace App\Application\Procurement\Rfq\Commands;

/**
 * Command to cancel an RFQ, release locked Mod 044 PR lines, and notify invited suppliers.
 */
final readonly class CancelRfqCommand
{
    public function __construct(
        public string $rfqId,
        public ?string $actorUserId,
        public ?string $reason = null,
    ) {}
}
