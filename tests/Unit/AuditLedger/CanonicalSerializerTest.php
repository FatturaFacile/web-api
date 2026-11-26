<?php

declare(strict_types=1);

use Enterprise\AuditLedger\Domain\Services\CanonicalSerializer;
use Enterprise\AuditLedger\Domain\ValueObjects\CanonicalPayload;
use Illuminate\Support\Carbon;

enum CanonicalProbeStatus: string
{
    case Open = 'open';
}

test('the canonical json does not depend on the order of the keys', function (): void {
    $a = ['zebra' => 1, 'apple' => ['y' => 2, 'x' => 1], 'list' => [3, 1, 2]];
    $b = ['list' => [3, 1, 2], 'apple' => ['x' => 1, 'y' => 2], 'zebra' => 1];

    expect(CanonicalSerializer::json($a))->toBe(CanonicalSerializer::json($b))
        ->and(CanonicalSerializer::json($a))->toBe('{"apple":{"x":1,"y":2},"list":[3,1,2],"zebra":1}');
});

test('lists keep their order while objects are sorted', function (): void {
    expect(CanonicalSerializer::json([['b' => 1, 'a' => 2], ['d' => 1, 'c' => 2]]))->toBe('[{"a":2,"b":1},{"c":2,"d":1}]');
});

test('decimal numbers are plain strings without exponent or trailing zeros', function (): void {
    expect(CanonicalSerializer::decimal(10.5))->toBe('10.5')
        ->and(CanonicalSerializer::decimal(100.0))->toBe('100')
        ->and(CanonicalSerializer::decimal(0.0001))->toBe('0.0001')
        ->and(CanonicalSerializer::decimal(-0.0))->toBe('0')
        ->and(CanonicalSerializer::decimal(1.0E+15))->toBe('1000000000000000')
        ->and(CanonicalSerializer::canonicalize(['amount' => 12.30, 'count' => 3]))->toBe(['amount' => '12.3', 'count' => 3]);
});

test('timestamps are iso 8601 in utc with microseconds whatever their zone', function (): void {
    $zurich = Carbon::parse('2027-03-04 10:00:00.123456', 'Europe/Zurich');

    expect(CanonicalSerializer::timestamp($zurich))->toBe('2027-03-04T09:00:00.123456Z')
        ->and(CanonicalSerializer::canonicalize(['at' => $zurich]))->toBe(['at' => '2027-03-04T09:00:00.123456Z']);
});

test('enums, booleans and null are normalised', function (): void {
    expect(CanonicalSerializer::canonicalize(['status' => CanonicalProbeStatus::Open, 'flag' => true, 'none' => null]))
        ->toBe(['flag' => true, 'none' => null, 'status' => 'open']);
});

test('text is normalised to unicode NFC so that composed and decomposed forms hash alike', function (): void {
    if (! class_exists(Normalizer::class)) {
        $this->markTestSkipped('ext-intl is not available.');
    }

    $composed = "caf\u{00E9}";
    $decomposed = "cafe\u{0301}";

    expect(CanonicalSerializer::json(['name' => $decomposed]))->toBe(CanonicalSerializer::json(['name' => $composed]));
});

test('the canonical payload wraps the json of any value', function (): void {
    $payload = CanonicalPayload::fromValue(['b' => 1, 'a' => 2]);

    expect((string) $payload)->toBe('{"a":2,"b":1}')
        ->and($payload->json)->toBe('{"a":2,"b":1}');
});

test('canonicalising twice gives the same document', function (): void {
    $once = CanonicalSerializer::canonicalize(['n' => 1.5, 'when' => Carbon::parse('2027-01-01 00:00:00', 'UTC'), 'z' => ['b' => 1, 'a' => 2]]);

    expect(CanonicalSerializer::canonicalize($once))->toBe($once);
});
