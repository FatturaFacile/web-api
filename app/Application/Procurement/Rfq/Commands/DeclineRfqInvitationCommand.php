<?php

declare(strict_types=1);

namespace App\Application\Procurement\Rfq\Commands;

/**
 * Command for an invited supplier to decline participation in an RFQ.
 */
final readonly class DeclineRfqInvitationCommand
{
    public function __construct(
        public string $invitationId,
        public ?string $reason,
    ) {}
}
