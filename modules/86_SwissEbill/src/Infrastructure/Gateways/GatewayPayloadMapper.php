<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Infrastructure\Gateways;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\SwissEbill\Application\Dto\GatewayNotification;
use Modules\SwissEbill\Application\Dto\ParticipantRecord;
use Modules\SwissEbill\Domain\Enums\EbillChannelType;
use Modules\SwissEbill\Domain\Enums\EbillNotificationType;
use Modules\SwissEbill\Domain\Enums\EbillParticipantStatus;
use Throwable;

/**
 * Maps the loosely named fields of the SIX Paynet and PostFinance JSON/XML answers (webhook bodies, status polls, directory
 * answers) onto the normalised DTOs of the module.
 */
final class GatewayPayloadMapper
{
    private const array TYPE_ALIASES = [
        'delivered' => EbillNotificationType::Delivered,
        'delivered_to_ebanking' => EbillNotificationType::Delivered,
        'received' => EbillNotificationType::Delivered,
        'approved' => EbillNotificationType::Approved,
        'payment_approved' => EbillNotificationType::Approved,
        'rejected' => EbillNotificationType::Rejected,
        'declined' => EbillNotificationType::Rejected,
        'settled' => EbillNotificationType::Settled,
        'paid' => EbillNotificationType::Settled,
        'failed' => EbillNotificationType::Failed,
        'error' => EbillNotificationType::Failed,
        'cancelled' => EbillNotificationType::Cancelled,
        'recalled' => EbillNotificationType::Cancelled,
    ];

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException when the type is unknown or the transaction id is missing
     */
    public static function notification(array $data, ?string $fallbackTransactionId = null): GatewayNotification
    {
        $rawType = strtolower((string) self::pick($data, ['type', 'eventType', 'event_type', 'status']));
        $type = self::TYPE_ALIASES[$rawType] ?? throw new InvalidArgumentException("Unknown eBill notification type [{$rawType}].");
        $transaction = (string) (self::pick($data, ['six_transaction_id', 'sixTransactionId', 'transaction_id', 'transactionId']) ?? $fallbackTransactionId);

        if ($transaction === '') {
            throw new InvalidArgumentException('The notification carries no transaction id.');
        }

        $eventId = (string) (self::pick($data, ['event_id', 'eventId', 'id']) ?? '');

        if ($eventId === '') {
            throw new InvalidArgumentException('The notification carries no event id.');
        }

        $payDate = self::pick($data, ['scheduled_payment_date', 'scheduledPaymentDate', 'paymentDate', 'payment_date']);

        return new GatewayNotification(
            eventId: $eventId,
            type: $type,
            sixTransactionId: $transaction,
            occurredAt: self::date(self::pick($data, ['occurred_at', 'occurredAt', 'timestamp'])) ?? CarbonImmutable::now(),
            scheduledPaymentDate: $payDate !== null ? self::date($payDate)?->startOfDay() : null,
            rejectionCode: self::nullableString(self::pick($data, ['rejection_code', 'rejectionCode', 'reasonCode'])),
            rejectionReason: self::nullableString(self::pick($data, ['rejection_reason', 'rejectionReason', 'reasonText', 'comment'])),
            errorCode: self::nullableString(self::pick($data, ['error_code', 'errorCode'])),
            errorDetails: self::nullableString(self::pick($data, ['error_details', 'errorDetails', 'errorMessage'])),
            settlementReference: self::nullableString(self::pick($data, ['settlement_reference', 'settlementReference'])),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function participant(array $data): ?ParticipantRecord
    {
        $id = (string) (self::pick($data, ['participant_id', 'participantId', 'recipientId', 'id']) ?? '');

        if ($id === '') {
            return null;
        }

        $status = strtolower((string) (self::pick($data, ['status', 'enrollmentStatus']) ?? 'enrolled'));
        $channel = strtolower((string) (self::pick($data, ['channel_type', 'channelType', 'type']) ?? ''));

        return new ParticipantRecord(
            participantId: $id,
            status: EbillParticipantStatus::tryFrom($status) ?? EbillParticipantStatus::Unknown,
            channelType: match (true) {
                in_array($channel, ['b2b', 'b2b_enterprise', 'business'], true) => EbillChannelType::B2bEnterprise,
                in_array($channel, ['b2c', 'b2c_private', 'private'], true) => EbillChannelType::B2cPrivate,
                default => null,
            },
            displayName: self::nullableString(self::pick($data, ['display_name', 'displayName', 'name'])),
            uid: self::nullableString(self::pick($data, ['uid'])),
            email: self::nullableString(self::pick($data, ['email'])),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private static function pick(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                return $data[$key];
            }
        }

        return null;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        try {
            return is_int($value) ? CarbonImmutable::createFromTimestamp($value) : CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
