<?php

declare(strict_types=1);

use Modules\SwissEbill\Domain\Enums\EbillChannelType;
use Modules\SwissEbill\Domain\Enums\EbillNotificationType;
use Modules\SwissEbill\Domain\Enums\EbillParticipantStatus;
use Modules\SwissEbill\Domain\Exceptions\EbillException;
use Modules\SwissEbill\Infrastructure\Gateways\GatewayPayloadMapper;
use Modules\SwissEbill\Infrastructure\Webhook\EbillWebhookPayloadParser;

test('a delivery receipt is normalised from the provider field names', function (): void {
    $n = GatewayPayloadMapper::notification(['eventType' => 'DELIVERED_TO_EBANKING', 'id' => 'ev-1', 'transactionId' => 'TX-1', 'timestamp' => '2026-03-05T10:00:00+01:00']);

    expect($n->type)->toBe(EbillNotificationType::Delivered)
        ->and($n->eventId)->toBe('ev-1')
        ->and($n->sixTransactionId)->toBe('TX-1')
        ->and($n->occurredAt->utc()->toIso8601String())->toBe('2026-03-05T09:00:00+00:00');
});

test('an approval carries the scheduled payment date', function (): void {
    $n = GatewayPayloadMapper::notification(['type' => 'approved', 'event_id' => 'ev-2', 'six_transaction_id' => 'TX-1', 'paymentDate' => '2026-03-20']);

    expect($n->type)->toBe(EbillNotificationType::Approved)->and($n->scheduledPaymentDate?->toDateString())->toBe('2026-03-20');
});

test('a rejection carries the bank code and the note of the debtor', function (): void {
    $n = GatewayPayloadMapper::notification(['type' => 'declined', 'event_id' => 'ev-3', 'transaction_id' => 'TX-1', 'reasonCode' => 'DUPLICATE', 'reasonText' => '  Paid last week  ']);

    expect($n->type)->toBe(EbillNotificationType::Rejected)
        ->and($n->rejectionCode)->toBe('DUPLICATE')
        ->and($n->rejectionReason)->toBe('Paid last week');
});

test('a status poll can supply the transaction id', function (): void {
    $n = GatewayPayloadMapper::notification(['type' => 'recalled', 'event_id' => 'ev-4'], 'TX-9');

    expect($n->type)->toBe(EbillNotificationType::Cancelled)->and($n->sixTransactionId)->toBe('TX-9');
});

test('an unknown type, a missing event id or a missing transaction is refused', function (array $data): void {
    expect(fn () => GatewayPayloadMapper::notification($data))->toThrow(InvalidArgumentException::class);
})->with([
    'unknown type' => [['type' => 'teleported', 'event_id' => 'e', 'transaction_id' => 't']],
    'no event id' => [['type' => 'delivered', 'transaction_id' => 't']],
    'no transaction' => [['type' => 'delivered', 'event_id' => 'e']],
]);

test('a directory answer maps to a participant', function (): void {
    $b2b = GatewayPayloadMapper::participant(['participantId' => '41000000000000001', 'status' => 'enrolled', 'channelType' => 'B2B', 'name' => 'Muster AG', 'uid' => 'CHE-100.000.012']);
    $b2c = GatewayPayloadMapper::participant(['participant_id' => 'P-2', 'status' => 'pending_opt_in', 'channel_type' => 'private']);

    expect($b2b->channelType)->toBe(EbillChannelType::B2bEnterprise)
        ->and($b2b->status)->toBe(EbillParticipantStatus::Enrolled)
        ->and($b2b->displayName)->toBe('Muster AG')
        ->and($b2c->channelType)->toBe(EbillChannelType::B2cPrivate)
        ->and($b2c->status)->toBe(EbillParticipantStatus::PendingOptIn)
        ->and(GatewayPayloadMapper::participant(['status' => 'enrolled']))->toBeNull();
});

test('the webhook parser reads one event or a list and refuses nonsense', function (): void {
    $parser = new EbillWebhookPayloadParser;

    expect($parser->parse(['type' => 'delivered', 'event_id' => 'a', 'transaction_id' => 't']))->toHaveCount(1)
        ->and($parser->parse(['events' => [['type' => 'delivered', 'event_id' => 'a', 'transaction_id' => 't'], ['type' => 'approved', 'event_id' => 'b', 'transaction_id' => 't']]]))->toHaveCount(2)
        ->and(fn () => $parser->parse(['type' => 'nope']))->toThrow(EbillException::class)
        ->and(fn () => $parser->parse(['events' => []]))->toThrow(EbillException::class)
        ->and(fn () => $parser->parse(['events' => ['not-an-event']]))->toThrow(EbillException::class);
});
