<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Domain\ValueObjects;

use InvalidArgumentException;
use Stringable;

/**
 * A 64 character lower case hexadecimal digest: the hash of one ledger entry or a chain seed.
 */
final readonly class ChainHash implements Stringable
{
    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        if (preg_match('/^[0-9a-f]{64}$/', $value) !== 1) {
            throw new InvalidArgumentException('A chain hash is 64 lower case hexadecimal characters.');
        }

        return new self($value);
    }

    /**
     * The hash that precedes the first entry of the legacy (version 1) chains.
     */
    public static function zero(): self
    {
        return new self(str_repeat('0', 64));
    }

    public function equals(self|string $other): bool
    {
        return hash_equals($this->value, $other instanceof self ? $other->value : $other);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
