<?php

declare(strict_types=1);

namespace Modules\SDI\DTOs;

/**
 * One thing read from an SDI channel: a receipt about a sent invoice, a supplier invoice, or nothing of interest.
 */
final readonly class SdiInboundItem
{
    public const string KindNotification = 'notification';

    public const string KindInvoice = 'invoice';

    public const string KindSkip = 'skip';

    private function __construct(
        public string $kind,
        public ?string $uuid = null,
        public ?string $eventType = null,
        public ?string $message = null,
        public ?string $fileName = null,
        public ?string $xml = null,
        public ?string $externalId = null,
        public ?int $cursor = null,
    ) {}

    /**
     * @param  string  $uuid  Identifier the channel gave to the transmission
     * @param  string  $eventType  RC, NS, MC, NEC1, NEC2, DT or AT
     */
    public static function notification(string $uuid, string $eventType, ?string $message = null, ?int $cursor = null): self
    {
        return new self(self::KindNotification, uuid: $uuid, eventType: $eventType, message: $message, cursor: $cursor);
    }

    public static function invoice(string $fileName, string $xml, string $externalId, ?int $cursor = null): self
    {
        return new self(self::KindInvoice, fileName: $fileName, xml: $xml, externalId: $externalId, cursor: $cursor);
    }

    public static function skip(int $cursor): self
    {
        return new self(self::KindSkip, cursor: $cursor);
    }
}
