<?php

declare(strict_types=1);

use Modules\SwissEbill\Domain\Enums\EbillNotificationType;
use Modules\SwissEbill\Domain\Enums\EbillRejectionCode;
use Modules\SwissEbill\Domain\Enums\EbillTransmissionStatus as Status;

/**
 * The state machine of an eBill transmission: the allowed moves are the only source of truth of the lifecycle.
 */
test('the happy path walks draft to paid', function (): void {
    $path = [Status::Draft, Status::Queued, Status::Transmitted, Status::DeliveredToEbanking, Status::ApprovedByDebtor, Status::PaidReconciled];

    foreach (array_slice($path, 0, -1) as $index => $from) {
        expect($from->canTransitionTo($path[$index + 1]))->toBeTrue("{$from->value} -> {$path[$index + 1]->value}");
    }
});

test('final states allow no move', function (Status $status): void {
    expect($status->isFinal())->toBeTrue()->and($status->allowedNext())->toBe([]);
})->with([Status::RejectedByDebtor, Status::PaidReconciled, Status::Cancelled]);

test('a debtor rejection or a recall is impossible once the payment is approved, except a late rejection', function (): void {
    expect(Status::ApprovedByDebtor->canTransitionTo(Status::Cancelled))->toBeFalse()
        ->and(Status::ApprovedByDebtor->canTransitionTo(Status::RejectedByDebtor))->toBeTrue()
        ->and(Status::ApprovedByDebtor->canTransitionTo(Status::PaidReconciled))->toBeTrue();
});

test('a failed transmission can only be queued again or cancelled', function (): void {
    expect(Status::FailedTechnical->allowedNext())->toBe([Status::Queued, Status::Cancelled]);
});

test('a settlement can overtake a late webhook', function (): void {
    expect(Status::Transmitted->canTransitionTo(Status::PaidReconciled))->toBeTrue()
        ->and(Status::DeliveredToEbanking->canTransitionTo(Status::PaidReconciled))->toBeTrue()
        ->and(Status::Queued->canTransitionTo(Status::PaidReconciled))->toBeFalse();
});

test('no state moves backwards on the happy path', function (): void {
    expect(Status::DeliveredToEbanking->canTransitionTo(Status::Transmitted))->toBeFalse()
        ->and(Status::Transmitted->canTransitionTo(Status::Queued))->toBeFalse()
        ->and(Status::PaidReconciled->canTransitionTo(Status::ApprovedByDebtor))->toBeFalse();
});

test('only the states the debtor can still see are in flight', function (): void {
    expect(array_map(static fn (Status $s): string => $s->value, Status::inFlight()))->toBe(['transmitted', 'delivered_to_ebanking', 'approved_by_debtor']);
});

test('ranks order the notifications of the network', function (): void {
    expect(Status::DeliveredToEbanking->rank())->toBeGreaterThan(Status::Transmitted->rank())
        ->and(Status::ApprovedByDebtor->rank())->toBeGreaterThan(Status::DeliveredToEbanking->rank());
});

test('every notification type maps to a status the lifecycle knows', function (): void {
    expect(EbillNotificationType::Delivered->targetStatus())->toBe(Status::DeliveredToEbanking)
        ->and(EbillNotificationType::Approved->targetStatus())->toBe(Status::ApprovedByDebtor)
        ->and(EbillNotificationType::Rejected->targetStatus())->toBe(Status::RejectedByDebtor)
        ->and(EbillNotificationType::Settled->targetStatus())->toBe(Status::PaidReconciled)
        ->and(EbillNotificationType::Failed->targetStatus())->toBe(Status::FailedTechnical)
        ->and(EbillNotificationType::Cancelled->targetStatus())->toBe(Status::Cancelled);
});

test('an unknown rejection code is kept as "other"', function (): void {
    expect(EbillRejectionCode::fromGateway('amount_mismatch'))->toBe(EbillRejectionCode::AmountMismatch)
        ->and(EbillRejectionCode::fromGateway('SOMETHING_NEW'))->toBe(EbillRejectionCode::Other)
        ->and(EbillRejectionCode::fromGateway(null))->toBe(EbillRejectionCode::Other);
});
