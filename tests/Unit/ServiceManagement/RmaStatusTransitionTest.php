<?php

declare(strict_types=1);

use Modules\ServiceManagement\Enums\RmaStatus;
use Modules\ServiceManagement\Enums\VendorClaimStatus;

test('RMA happy path transitions are allowed', function (RmaStatus $from, RmaStatus $to) {
    expect($from->canTransitionTo($to))->toBeTrue();
})->with([
    'receive' => [RmaStatus::AwaitingArrival, RmaStatus::InDiagnosis],
    'estimate needed' => [RmaStatus::InDiagnosis, RmaStatus::AwaitingEstimateApproval],
    'covered with parts to order' => [RmaStatus::InDiagnosis, RmaStatus::AwaitingParts],
    'covered with parts in stock' => [RmaStatus::InDiagnosis, RmaStatus::InRepairTesting],
    'not repairable' => [RmaStatus::InDiagnosis, RmaStatus::ReadyForReturn],
    'approved, parts to order' => [RmaStatus::AwaitingEstimateApproval, RmaStatus::AwaitingParts],
    'approved' => [RmaStatus::AwaitingEstimateApproval, RmaStatus::InRepairTesting],
    'declined' => [RmaStatus::AwaitingEstimateApproval, RmaStatus::ReadyForReturn],
    'parts received' => [RmaStatus::AwaitingParts, RmaStatus::InRepairTesting],
    'repair complete' => [RmaStatus::InRepairTesting, RmaStatus::ReadyForReturn],
    'return' => [RmaStatus::ReadyForReturn, RmaStatus::Returned],
    'close' => [RmaStatus::Returned, RmaStatus::Closed],
    'cancel before arrival' => [RmaStatus::AwaitingArrival, RmaStatus::Cancelled],
    'cancel in diagnosis' => [RmaStatus::InDiagnosis, RmaStatus::Cancelled],
]);

test('illegal RMA transitions are rejected', function (RmaStatus $from, RmaStatus $to) {
    expect($from->canTransitionTo($to))->toBeFalse();
})->with([
    'return from diagnosis' => [RmaStatus::InDiagnosis, RmaStatus::Returned],
    'skip diagnosis' => [RmaStatus::AwaitingArrival, RmaStatus::InRepairTesting],
    'cancel after repair' => [RmaStatus::InRepairTesting, RmaStatus::Cancelled],
    'reopen closed' => [RmaStatus::Closed, RmaStatus::InDiagnosis],
    'reopen cancelled' => [RmaStatus::Cancelled, RmaStatus::AwaitingArrival],
    'close before return' => [RmaStatus::ReadyForReturn, RmaStatus::Closed],
]);

test('terminal statuses have no outgoing transitions and the board hides cancelled', function () {
    expect(RmaStatus::Closed->allowedTransitions())->toBe([])
        ->and(RmaStatus::Cancelled->allowedTransitions())->toBe([])
        ->and(RmaStatus::Closed->isTerminal())->toBeTrue()
        ->and(RmaStatus::Returned->isTerminal())->toBeFalse()
        ->and(RmaStatus::boardColumns())->toHaveCount(8)
        ->and(RmaStatus::boardColumns())->not->toContain(RmaStatus::Cancelled)
        ->and(RmaStatus::AwaitingArrival->label())->toBe('Attesa arrivo merce');
});

test('vendor claim state machine follows draft -> submitted -> acknowledged -> resolution -> closed', function () {
    expect(VendorClaimStatus::Draft->canTransitionTo(VendorClaimStatus::Submitted))->toBeTrue()
        ->and(VendorClaimStatus::Submitted->canTransitionTo(VendorClaimStatus::Acknowledged))->toBeTrue()
        ->and(VendorClaimStatus::Acknowledged->canTransitionTo(VendorClaimStatus::ApprovedCreditNote))->toBeTrue()
        ->and(VendorClaimStatus::Acknowledged->canTransitionTo(VendorClaimStatus::ApprovedReplacement))->toBeTrue()
        ->and(VendorClaimStatus::Acknowledged->canTransitionTo(VendorClaimStatus::Rejected))->toBeTrue()
        ->and(VendorClaimStatus::Rejected->canTransitionTo(VendorClaimStatus::Closed))->toBeTrue()
        ->and(VendorClaimStatus::Draft->canTransitionTo(VendorClaimStatus::Closed))->toBeFalse()
        ->and(VendorClaimStatus::Submitted->canTransitionTo(VendorClaimStatus::ApprovedCreditNote))->toBeFalse()
        ->and(VendorClaimStatus::Closed->allowedTransitions())->toBe([]);
});
