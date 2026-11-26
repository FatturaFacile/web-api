<?php

declare(strict_types=1);

use Enterprise\AuditLedger\Domain\AuditChain;
use Enterprise\AuditLedger\Domain\Enums\VerificationOutcome;
use Enterprise\AuditLedger\Domain\Services\ChainHasher;
use Enterprise\AuditLedger\Domain\Services\IntegrityVerifier;
use Illuminate\Support\Carbon;
use Tests\Support\AuditLedgerFixtures;

const AUDIT_VERIFIER_TENANT = '0194f7a0-2222-7000-8000-000000000001';

/**
 * A valid in-memory chain of $length entries, signed with the key version of each position (default: all version 1).
 *
 * @param  list<int>  $versions
 * @return list<array<string, mixed>>
 */
function auditVerifierChain(ChainHasher $hasher, int $length, array $versions = []): array
{
    $chain = AuditChain::resume(AUDIT_VERIFIER_TENANT, $hasher);
    $rows = [];

    foreach (range(1, $length) as $i) {
        $rows[] = $chain->append(
            AuditLedgerFixtures::entryFields(AUDIT_VERIFIER_TENANT, ['changes' => ['old' => ['n' => $i - 1], 'new' => ['n' => $i]]]),
            $versions[$i - 1] ?? 1,
            Carbon::parse('2027-01-01 10:00:00', 'UTC')->addSeconds($i),
        );
    }

    return $rows;
}

beforeEach(function (): void {
    $this->hasher = new ChainHasher(AuditLedgerFixtures::keyring());
    $this->verifier = new IntegrityVerifier($this->hasher);
});

test('an intact chain is valid', function (): void {
    $rows = auditVerifierChain($this->hasher, 6);
    $result = $this->verifier->verify($rows);

    expect($result->isValid())->toBeTrue()
        ->and($result->outcome)->toBe(VerificationOutcome::Valid)
        ->and($result->verifiedEntries)->toBe(6)
        ->and($result->lastSequence)->toBe(6)
        ->and($result->lastHash)->toBe($rows[5]['record_hash']);
});

test('an empty chain is valid', function (): void {
    expect($this->verifier->verify([])->isValid())->toBeTrue();
});

test('a chain whose keys were rotated verifies with the version each entry carries', function (): void {
    $rows = auditVerifierChain($this->hasher, 6, [1, 1, 1, 2, 2, 2]);

    expect($this->verifier->verify($rows)->isValid())->toBeTrue()
        ->and(array_column($rows, 'key_version'))->toBe([1, 1, 1, 2, 2, 2]);
});

test('an altered record is reported broken at its exact sequence', function (string $field, mixed $value): void {
    $rows = auditVerifierChain($this->hasher, 6);
    $rows[3][$field] = $value;

    $result = $this->verifier->verify($rows);

    expect($result->outcome)->toBe(VerificationOutcome::BrokenAt)
        ->and($result->brokenAtSequence)->toBe(4)
        ->and($result->verifiedEntries)->toBe(3);
})->with([
    ['action', 'DELETED'],
    ['changes', ['old' => ['n' => 99], 'new' => ['n' => 100]]],
    ['actor_ref', '~pk:'.str_repeat('f', 32)],
    ['resource_id', '0194f7a0-0000-7000-8000-0000000000ff'],
    ['record_hash', str_repeat('e', 64)],
]);

test('a record that no longer links to the one before is broken at its sequence', function (): void {
    $rows = auditVerifierChain($this->hasher, 5);
    $rows[2]['previous_hash'] = str_repeat('a', 64);

    $result = $this->verifier->verify($rows);

    expect($result->outcome)->toBe(VerificationOutcome::BrokenAt)->and($result->brokenAtSequence)->toBe(3);
});

test('a deleted record in the middle is a missing range', function (): void {
    $rows = auditVerifierChain($this->hasher, 8);
    unset($rows[3], $rows[4]);

    $result = $this->verifier->verify(array_values($rows));

    expect($result->outcome)->toBe(VerificationOutcome::MissingRange)
        ->and($result->missingFromSequence)->toBe(4)
        ->and($result->missingToSequence)->toBe(5)
        ->and($result->verifiedEntries)->toBe(3);
});

test('records missing at the start of the chain are a missing range', function (): void {
    $rows = auditVerifierChain($this->hasher, 5);

    $result = $this->verifier->verify(array_slice($rows, 2));

    expect($result->outcome)->toBe(VerificationOutcome::MissingRange)
        ->and($result->missingFromSequence)->toBe(1)
        ->and($result->missingToSequence)->toBe(2);
});

test('a forged record inserted into a gap is detected', function (): void {
    $rows = auditVerifierChain($this->hasher, 6);
    $forged = $rows[2];
    $forged['action'] = 'FORGED';
    $rows[2] = $forged;

    $result = $this->verifier->verify($rows);

    expect($result->outcome)->toBe(VerificationOutcome::BrokenAt)->and($result->brokenAtSequence)->toBe(3);
});

test('a duplicated sequence is broken at that sequence', function (): void {
    $rows = auditVerifierChain($this->hasher, 4);
    array_splice($rows, 2, 0, [$rows[1]]);

    $result = $this->verifier->verify($rows);

    expect($result->outcome)->toBe(VerificationOutcome::BrokenAt)->and($result->brokenAtSequence)->toBe(2);
});

test('a removed tail is a missing range when a checkpoint pinned it', function (): void {
    $rows = auditVerifierChain($this->hasher, 6);
    $anchors = [6 => $rows[5]['record_hash']];

    $result = $this->verifier->verify(array_slice($rows, 0, 4), $anchors);

    expect($result->outcome)->toBe(VerificationOutcome::MissingRange)
        ->and($result->missingFromSequence)->toBe(5)
        ->and($result->missingToSequence)->toBe(6);
});

test('a checkpoint that the chain no longer reproduces is broken at its sequence', function (): void {
    $rows = auditVerifierChain($this->hasher, 6);

    $result = $this->verifier->verify($rows, [3 => str_repeat('0', 64)]);

    expect($result->outcome)->toBe(VerificationOutcome::BrokenAt)->and($result->brokenAtSequence)->toBe(3);
});

test('the result serialises for the api', function (): void {
    $rows = auditVerifierChain($this->hasher, 4);
    unset($rows[1]);

    expect($this->verifier->verify(array_values($rows))->toArray())->toMatchArray([
        'outcome' => 'missing_range',
        'missing_range' => ['from' => 2, 'to' => 2],
    ]);
});
