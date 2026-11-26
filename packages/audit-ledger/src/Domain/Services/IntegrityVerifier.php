<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Domain\Services;

use Enterprise\AuditLedger\Domain\ValueObjects\VerificationResult;
use Illuminate\Database\Eloquent\Model;

/**
 * Verifies a chain of entries read in sequence order. It stops at the first problem and says exactly where:
 *  - a gap in the sequences is a missing range (records were deleted);
 *  - a record whose stored hash is not the one recomputed, or whose previous_hash does not link to the entry before, or
 *    that repeats or reverses a sequence, is broken at that sequence (altered or forged records);
 *  - an anchor (a checkpoint hash) that the chain no longer reproduces is broken at the anchor's sequence, and an anchor
 *    beyond the last entry is a missing range (the tail was cut).
 */
final class IntegrityVerifier
{
    public function __construct(private readonly ChainHasher $hasher) {}

    /**
     * @param  iterable<array<string, mixed>|Model>  $entries  ordered by ascending sequence
     * @param  array<int, string>  $anchors  checkpoint hashes by sequence
     */
    public function verify(iterable $entries, array $anchors = [], int $firstSequence = 1): VerificationResult
    {
        $previousHash = null;
        $previousSequence = null;
        $verified = 0;

        foreach ($entries as $entry) {
            $sequence = (int) $this->value($entry, 'sequence');

            if ($previousSequence === null) {
                if ($sequence > $firstSequence) {
                    return VerificationResult::missingRange($firstSequence, $sequence - 1, $verified, "Entries {$firstSequence}..".($sequence - 1).' are missing at the start of the chain.');
                }

                $previousHash = $this->hasher->genesisFor($entry);
            } elseif ($sequence > $previousSequence + 1) {
                return VerificationResult::missingRange($previousSequence + 1, $sequence - 1, $verified, 'Entries '.($previousSequence + 1).'..'.($sequence - 1).' are missing.');
            } elseif ($sequence <= $previousSequence) {
                return VerificationResult::brokenAt($sequence, $verified, "Sequence {$sequence} repeats or goes backwards after {$previousSequence}.");
            }

            $stored = (string) $this->value($entry, 'previous_hash');

            if (! hash_equals((string) $previousHash, $stored)) {
                return VerificationResult::brokenAt($sequence, $verified, "Entry {$sequence} does not link to the hash of the entry before it.");
            }

            $recomputed = $this->hasher->expectedHash($entry, $stored);

            if (! hash_equals($recomputed, (string) $this->value($entry, 'record_hash'))) {
                return VerificationResult::brokenAt($sequence, $verified, "Entry {$sequence} was altered: its hash does not match its content.");
            }

            if (isset($anchors[$sequence]) && ! hash_equals($anchors[$sequence], $recomputed)) {
                return VerificationResult::brokenAt($sequence, $verified, "Entry {$sequence} does not match its checkpoint.");
            }

            $previousHash = $recomputed;
            $previousSequence = $sequence;
            $verified++;
        }

        $anchorsBeyond = array_filter(array_keys($anchors), static fn (int $sequence): bool => $sequence > ($previousSequence ?? 0));

        if ($anchorsBeyond !== []) {
            $last = $previousSequence ?? 0;

            return VerificationResult::missingRange($last + 1, max($anchorsBeyond), $verified, 'The chain ends before its last checkpoint: the tail was removed.');
        }

        return VerificationResult::valid($verified, $previousSequence, $previousHash);
    }

    /**
     * @param  array<string, mixed>|Model  $entry
     */
    private function value(array|Model $entry, string $key): mixed
    {
        return $entry instanceof Model ? $entry->getAttribute($key) : ($entry[$key] ?? null);
    }
}
