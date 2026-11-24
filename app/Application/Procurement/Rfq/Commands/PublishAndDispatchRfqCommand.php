<?php

declare(strict_types=1);

namespace App\Application\Procurement\Rfq\Commands;

/**
 * Command to publish a draft RFQ, generate 256-bit invitation tokens, and dispatch invitation emails.
 */
final readonly class PublishAndDispatchRfqCommand
{
    public function __construct(
        public string $rfqId,
        public ?string $actorUserId,
    ) {}
}
