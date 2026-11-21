<?php

declare(strict_types=1);

namespace Modules\Quoting\Domain\ValueObjects;

use InvalidArgumentException;
use Stringable;

/** SHA-256 fingerprint of a frozen payload or of the final signed document. */
final readonly class DocumentHash implements Stringable
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new InvalidArgumentException('A document hash is 64 lowercase hex characters (SHA-256).');
        }
    }

    public static function ofBytes(string $bytes): self
    {
        return new self(hash('sha256', $bytes));
    }

    /** Canonical hash of a payload: keys sorted recursively, so the same content always has the same fingerprint. */
    public static function ofPayload(array $payload): self
    {
        return self::ofBytes((string) json_encode(self::canonical($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function equals(self|string $other): bool
    {
        return hash_equals($this->value, (string) $other);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::canonical(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
