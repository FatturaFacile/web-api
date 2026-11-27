<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Dto;

use Carbon\CarbonImmutable;
use Modules\SwissEbill\Domain\Enums\EbillNotificationType;

/**
 * One status notification of the gateway, normalised: the same shape comes from a webhook body and from a status poll.
 */
final readonly class GatewayNotification
{
    public function __construct(
        public string $eventId,
        public EbillNotificationType $type,
        public string $sixTransactionId,
        public CarbonImmutable $occurredAt,
        public ?CarbonImmutable $scheduledPaymentDate = null,
        public ?string $rejectionCode = null,
        public ?string $rejectionReason = null,
        public ?string $errorCode = null,
        public ?string $errorDetails = null,
        public ?string $settlementReference = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'type' => $this->type->value,
            'six_transaction_id' => $this->sixTransactionId,
            'occurred_at' => $this->occurredAt->toIso8601String(),
            'scheduled_payment_date' => $this->scheduledPaymentDate?->toDateString(),
            'rejection_code' => $this->rejectionCode,
            'rejection_reason' => $this->rejectionReason,
            'error_code' => $this->errorCode,
            'error_details' => $this->errorDetails,
            'settlement_reference' => $this->settlementReference,
        ];
    }
}
