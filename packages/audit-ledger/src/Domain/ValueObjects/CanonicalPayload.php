<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Domain\ValueObjects;

use Enterprise\AuditLedger\Domain\Services\CanonicalSerializer;
use Stringable;

/**
 * The canonical JSON of a document (sorted keys, decimal numbers as strings, UTC ISO 8601 timestamps): the same data
 * gives the same bytes on every database engine and whatever the key order it was read back in.
 */
final readonly class CanonicalPayload implements Stringable
{
    private function __construct(public string $json) {}

    public static function fromValue(mixed $value): self
    {
        return new self(CanonicalSerializer::json($value));
    }

    public function __toString(): string
    {
        return $this->json;
    }
}
