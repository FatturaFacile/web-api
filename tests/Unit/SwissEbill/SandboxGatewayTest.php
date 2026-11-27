<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Modules\SwissEbill\Application\Dto\GatewaySubmission;
use Modules\SwissEbill\Application\Dto\ParticipantLookupCriteria;
use Modules\SwissEbill\Domain\Enums\EbillChannelType;
use Modules\SwissEbill\Domain\Enums\EbillNotificationType;
use Modules\SwissEbill\Domain\Enums\EbillParticipantStatus;
use Modules\SwissEbill\Domain\Exceptions\EbillGatewayException;
use Modules\SwissEbill\Domain\Support\BillerIdValidator;
use Modules\SwissEbill\Infrastructure\Gateways\SandboxEbillGateway;
use Modules\SwissEbill\Models\EbillSetting;

beforeEach(function (): void {
    Cache::flush();
    $this->travelTo('2026-03-05 10:00:00');
    $this->gateway = new SandboxEbillGateway;
    $this->setting = new EbillSetting;
    $body = '41'.'10560425610173';
    $this->biller = $body.BillerIdValidator::checkDigit($body);
});

function sandboxSubmission(string $biller, string $recipient, string $key = 'key-1', string $xml = '<a/>'): GatewaySubmission
{
    return new GatewaySubmission($key, $biller, $recipient, EbillChannelType::B2bEnterprise, 'INV-1', $xml, hash('sha256', $xml));
}

test('the simulator is always reachable', function (): void {
    $result = $this->gateway->testConnection($this->setting);

    expect($result->isConnected())->toBeTrue()->and($result->environment)->toBe('sandbox');
});

test('directory rules follow the last digit of the identifier', function (): void {
    $enrolled = $this->gateway->lookupParticipant($this->setting, ParticipantLookupCriteria::iban('CH9300762011623852957'));
    $notEnrolled = $this->gateway->lookupParticipant($this->setting, ParticipantLookupCriteria::iban('CH4431999123000889012'));

    expect($enrolled)->not->toBeNull()
        ->and($enrolled->status)->toBe(EbillParticipantStatus::Enrolled)
        ->and($enrolled->channelType)->toBe(EbillChannelType::B2cPrivate)
        ->and(BillerIdValidator::isValid($enrolled->participantId))->toBeTrue()
        ->and($notEnrolled)->toBeNull();
});

test('email markers pick the participant state', function (string $email, ?EbillParticipantStatus $expected): void {
    $record = $this->gateway->lookupParticipant($this->setting, ParticipantLookupCriteria::email($email));

    expect($record?->status)->toBe($expected);
})->with([
    ['mario.ebill@example.com', EbillParticipantStatus::Enrolled],
    ['anna.optin@example.com', EbillParticipantStatus::PendingOptIn],
    ['paul.suspended@example.com', EbillParticipantStatus::Suspended],
    ['someone@example.com', null],
]);

test('a submission is idempotent on its key', function (): void {
    $first = $this->gateway->submit($this->setting, sandboxSubmission($this->biller, '41000000000000000'));
    $again = $this->gateway->submit($this->setting, sandboxSubmission($this->biller, '41000000000000000'));
    $other = $this->gateway->submit($this->setting, sandboxSubmission($this->biller, '41000000000000000', 'key-2'));

    expect($again->sixTransactionId)->toBe($first->sixTransactionId)->and($other->sixTransactionId)->not->toBe($first->sixTransactionId);
});

test('a malformed payload or an unknown biller is refused', function (): void {
    expect(fn () => $this->gateway->submit($this->setting, sandboxSubmission($this->biller, '41000000000000000', 'k', '<broken')))
        ->toThrow(EbillGatewayException::class, 'well-formed');

    try {
        $this->gateway->submit($this->setting, sandboxSubmission('12345678901234567', '41000000000000000', 'k3'));
    } catch (EbillGatewayException $e) {
        expect($e->gatewayStatus)->toBe(403)->and($e->isTransient())->toBeFalse();

        return;
    }

    throw new RuntimeException('The unknown biller should have been refused.');
});

test('the status timeline follows the time after the submission', function (): void {
    $receipt = $this->gateway->submit($this->setting, sandboxSubmission($this->biller, '41000000000000001'));

    expect($this->gateway->fetchStatus($this->setting, $receipt->sixTransactionId))->toBe([]);

    $this->travelTo(now()->addMinutes(2));
    $events = $this->gateway->fetchStatus($this->setting, $receipt->sixTransactionId);
    expect($events)->toHaveCount(1)->and($events[0]->type)->toBe(EbillNotificationType::Delivered);

    $this->travelTo(now()->addMinutes(2));
    $events = $this->gateway->fetchStatus($this->setting, $receipt->sixTransactionId);
    expect($events)->toHaveCount(2)
        ->and($events[1]->type)->toBe(EbillNotificationType::Approved)
        ->and($events[1]->scheduledPaymentDate?->toDateString())->toBe('2026-03-10');
});

test('a recipient ending in 9 rejects and one ending in 8 stays in the e-banking', function (): void {
    $rejecting = $this->gateway->submit($this->setting, sandboxSubmission($this->biller, '41000000000000009', 'k-rej'));
    $waiting = $this->gateway->submit($this->setting, sandboxSubmission($this->biller, '41000000000000008', 'k-wait'));
    $this->travelTo(now()->addMinutes(5));

    $rejected = $this->gateway->fetchStatus($this->setting, $rejecting->sixTransactionId);
    $stuck = $this->gateway->fetchStatus($this->setting, $waiting->sixTransactionId);

    expect($rejected[1]->type)->toBe(EbillNotificationType::Rejected)
        ->and($rejected[1]->rejectionCode)->toBe('AMOUNT_MISMATCH')
        ->and($stuck)->toHaveCount(1)
        ->and($stuck[0]->type)->toBe(EbillNotificationType::Delivered);
});

test('a recall works until the debtor decided', function (): void {
    $early = $this->gateway->submit($this->setting, sandboxSubmission($this->biller, '41000000000000001', 'k-early'));
    $this->gateway->recall($this->setting, $early->sixTransactionId);
    expect($this->gateway->fetchStatus($this->setting, $early->sixTransactionId)[0]->type)->toBe(EbillNotificationType::Cancelled);

    $late = $this->gateway->submit($this->setting, sandboxSubmission($this->biller, '41000000000000001', 'k-late'));
    $this->travelTo(now()->addMinutes(5));

    expect(fn () => $this->gateway->recall($this->setting, $late->sixTransactionId))->toThrow(EbillGatewayException::class)
        ->and(fn () => $this->gateway->fetchStatus($this->setting, 'SBX-UNKNOWN'))->toThrow(EbillGatewayException::class);
});
