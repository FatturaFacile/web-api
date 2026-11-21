<?php

declare(strict_types=1);

namespace App\Application\Procurement\Rfq\Commands;

/**
 * Command to extend an RFQ submission deadline and update invitation token expiry.
 */
final readonly class ExtendRfqDeadlineCommand
{
    public function __construct(
        public string $rfqId,
        public string $newSubmissionDeadline,
        public ?string $actorUserId,
    ) {}
}
