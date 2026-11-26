<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Domain\ValueObjects;

use InvalidArgumentException;
use Stringable;

/**
 * An opaque reference that stands for a person or a personal value inside the chain. What it stands for lives in
 * `audit_subject_map` and is deleted on erasure; the token itself stays and keeps the chain valid.
 */
final readonly class PseudonymToken implements Stringable
{
    public const string PREFIX = '~pk:';

    private function __construct(public string $value) {}

    public static function fromDigest(string $hexDigest): self
    {
        return new self(self::PREFIX.substr($hexDigest, 0, 32));
    }

    public static function fromString(string $value): self
    {
        if (! self::looksLikeToken($value)) {
            throw new InvalidArgumentException('Not a pseudonym token.');
        }

        return new self($value);
    }

    public static function looksLikeToken(mixed $value): bool
    {
        return is_string($value) && preg_match('/^~pk:[0-9a-f]{32}$/', $value) === 1;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
