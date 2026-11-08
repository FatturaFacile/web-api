<?php

declare(strict_types=1);

namespace Modules\Communication\DTOs;

/**
 * Immutable data carrier: delivery message.
 */
final readonly class DeliveryMessage
{
    /**
     * Inject the collaborators.
     */
    public function __construct(
        public string $channel,
        public string $recipient,
        public string $subject,
        public string $body,
        public ?string $attachmentName = null,
        public ?string $attachmentContent = null,
    ) {}
}
