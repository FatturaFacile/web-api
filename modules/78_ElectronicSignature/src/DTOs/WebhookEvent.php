<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\DTOs;

/**
 * A verified callback of a provider, normalised: `recipient_signed`, `recipient_rejected`, `envelope_completed` or
 * `envelope_expired`.
 */
final readonly class WebhookEvent
{
    public const string RecipientSigned = 'recipient_signed';

    public const string RecipientRejected = 'recipient_rejected';

    public const string EnvelopeCompleted = 'envelope_completed';

    public const string EnvelopeExpired = 'envelope_expired';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $type,
        public string $eventId,
        public string $envelopeExternalId,
        public ?string $recipientExternalId = null,
        public ?string $reason = null,
        public ?string $certificateSerial = null,
        public array $payload = [],
    ) {}
}
