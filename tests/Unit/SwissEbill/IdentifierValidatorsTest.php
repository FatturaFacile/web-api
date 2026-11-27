<?php

declare(strict_types=1);

use Modules\SwissEbill\Application\Dto\ParticipantLookupCriteria;
use Modules\SwissEbill\Domain\Support\BillerIdValidator;
use Modules\SwissEbill\Domain\Support\SwissUid;

function ebillValidBillerId(string $fourteenDigits = '10560425610173'): string
{
    $body = '41'.$fourteenDigits;

    return $body.BillerIdValidator::checkDigit($body);
}

test('a biller id is 17 digits with a Luhn check digit', function (): void {
    $valid = ebillValidBillerId();

    expect(BillerIdValidator::isValid($valid))->toBeTrue()
        ->and(BillerIdValidator::isValid(substr($valid, 0, 16).(((int) $valid[16] + 1) % 10)))->toBeFalse()
        ->and(BillerIdValidator::isValid('4101056042561017'))->toBeFalse()
        ->and(BillerIdValidator::isValid('99'.substr($valid, 2)))->toBeFalse()
        ->and(BillerIdValidator::isValid('abc'))->toBeFalse();
});

test('a biller id is accepted with spaces and dashes', function (): void {
    $valid = ebillValidBillerId();

    expect(BillerIdValidator::normalize(substr($valid, 0, 4).' '.substr($valid, 4, 4).'-'.substr($valid, 8)))->toBe($valid);
});

test('a swiss UID is normalised and checked with modulo 11', function (): void {
    $body = null;

    for ($n = 10000001; $n < 10000200 && $body === null; $n++) {
        $body = SwissUid::complete((string) $n);
    }

    $uid = 'CHE-'.substr($body, 0, 3).'.'.substr($body, 3, 3).'.'.substr($body, 6, 3);
    $wrong = 'CHE-'.substr($body, 0, 3).'.'.substr($body, 3, 3).'.'.substr($body, 6, 2).(((int) $body[8] + 1) % 10);

    expect(SwissUid::normalize($uid))->toBe($uid)
        ->and(SwissUid::normalize($uid.' MWST'))->toBe($uid)
        ->and(SwissUid::normalize(str_replace(['-', '.'], '', $uid)))->toBe($uid)
        ->and(SwissUid::normalize($wrong))->toBeNull()
        ->and(SwissUid::normalize('IT12345678901'))->toBeNull()
        ->and(SwissUid::normalize(null))->toBeNull()
        ->and(SwissUid::isValid($uid))->toBeTrue();
});

test('a lookup criterion carries one canonical identifier', function (): void {
    expect(ParticipantLookupCriteria::email(' Info@Example.COM ')->value)->toBe('info@example.com')
        ->and(ParticipantLookupCriteria::iban('ch44 3199 9123 0008 8901 2')->value)->toBe('CH4431999123000889012')
        ->and(ParticipantLookupCriteria::participantId(' 41000000000000001 ')->value)->toBe('41000000000000001')
        ->and(ParticipantLookupCriteria::email('a@b.ch')->cacheKey())->toBe(ParticipantLookupCriteria::email('A@B.ch')->cacheKey())
        ->and(ParticipantLookupCriteria::email('a@b.ch')->cacheKey())->not->toBe(ParticipantLookupCriteria::participantId('a@b.ch')->cacheKey());
});

test('an invalid UID or an unknown type cannot become a criterion', function (): void {
    expect(fn () => ParticipantLookupCriteria::uid('CHE-123.456.789'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ParticipantLookupCriteria::of('phone', '123'))->toThrow(InvalidArgumentException::class);
});
