<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Domain\ValueObjects;

use Enterprise\AuditLedger\Domain\Enums\VerificationOutcome;

/**
 * The outcome of verifying a chain: valid, broken at a sequence (a record was altered or forged) or a missing range of
 * sequences (records were deleted).
 */
final readonly class VerificationResult
{
    public function __construct(
        public VerificationOutcome $outcome,
        public int $verifiedEntries,
        public ?int $brokenAtSequence = null,
        public ?int $missingFromSequence = null,
        public ?int $missingToSequence = null,
        public ?int $lastSequence = null,
        public ?string $lastHash = null,
        public ?string $message = null,
    ) {}

    public static function valid(int $verified, ?int $lastSequence, ?string $lastHash): self
    {
        return new self(VerificationOutcome::Valid, $verified, null, null, null, $lastSequence, $lastHash);
    }

    public static function brokenAt(int $sequence, int $verified, string $message): self
    {
        return new self(VerificationOutcome::BrokenAt, $verified, $sequence, null, null, null, null, $message);
    }

    public static function missingRange(int $from, int $to, int $verified, string $message): self
    {
        return new self(VerificationOutcome::MissingRange, $verified, null, $from, $to, null, null, $message);
    }

    public function isValid(): bool
    {
        return $this->outcome === VerificationOutcome::Valid;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'verified_entries' => $this->verifiedEntries,
            'broken_at_sequence' => $this->brokenAtSequence,
            'missing_range' => $this->missingFromSequence === null ? null : ['from' => $this->missingFromSequence, 'to' => $this->missingToSequence],
            'message' => $this->message,
        ];
    }
}
