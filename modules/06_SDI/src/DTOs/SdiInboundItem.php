<?php

declare(strict_types=1);

namespace Modules\SDI\DTOs;

/**
 * One thing read from an SDI channel: a receipt about a sent invoice, a supplier invoice, a message that could not be
 * read or trusted and must be kept aside (quarantine), or nothing of interest.
 */
final readonly class SdiInboundItem
{
    public const string KindNotification = 'notification';

    public const string KindInvoice = 'invoice';

    public const string KindQuarantine = 'quarantine';

    public const string KindSkip = 'skip';

    /**
     * @param  list<string>  $reasons  Why a quarantined message was kept aside
     * @param  array<string, mixed>  $details  Envelope data of a quarantined message (subject, sender, attachments)
     */
    private function __construct(
        public string $kind,
        public ?string $uuid = null,
        public ?string $eventType = null,
        public ?string $message = null,
        public ?string $fileName = null,
        public ?string $xml = null,
        public ?string $externalId = null,
        public ?int $cursor = null,
        public ?bool $signatureVerified = null,
        public ?string $raw = null,
        public ?string $sourceReference = null,
        public array $reasons = [],
        public array $details = [],
    ) {}

    /**
     * @param  string  $uuid  Identifier the channel gave to the transmission
     * @param  string  $eventType  RC, NS, MC, NEC1, NEC2, DT or AT
     */
    public static function notification(string $uuid, string $eventType, ?string $message = null, ?int $cursor = null): self
    {
        return new self(self::KindNotification, uuid: $uuid, eventType: $eventType, message: $message, cursor: $cursor);
    }

    /**
     * @param  bool|null  $signatureVerified  Whether the CAdES signature of a .p7m file verifies, null for a plain XML
     */
    public static function invoice(string $fileName, string $xml, string $externalId, ?int $cursor = null, ?bool $signatureVerified = null): self
    {
        return new self(self::KindInvoice, fileName: $fileName, xml: $xml, externalId: $externalId, cursor: $cursor, signatureVerified: $signatureVerified);
    }

    /**
     * A message kept whole so that nothing it carries is lost: the cursor of the channel may move past it only once it
     * is stored.
     *
     * @param  list<string>  $reasons
     * @param  array<string, mixed>  $details
     */
    public static function quarantine(string $raw, string $sourceReference, array $reasons, array $details = [], ?int $cursor = null, ?bool $signatureVerified = null): self
    {
        return new self(
            self::KindQuarantine,
            cursor: $cursor,
            signatureVerified: $signatureVerified,
            raw: $raw,
            sourceReference: $sourceReference,
            reasons: $reasons,
            details: $details,
        );
    }

    public static function skip(int $cursor): self
    {
        return new self(self::KindSkip, cursor: $cursor);
    }
}
