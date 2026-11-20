<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * A validated SHA-256 digest in lowercase hexadecimal.
 */
final readonly class Sha256Hash implements \Stringable
{
    private function __construct(public string $hex) {}

    /**
     * Hash a binary or text string.
     */
    public static function ofContent(string $content): self
    {
        return new self(hash('sha256', $content));
    }

    /**
     * Wrap an existing hexadecimal digest.
     *
     * @throws InvalidArgumentException when the value is not 64 hexadecimal characters
     */
    public static function fromHex(string $hex): self
    {
        $hex = strtolower(trim($hex));

        if (preg_match('/^[0-9a-f]{64}$/', $hex) !== 1) {
            throw new InvalidArgumentException('A SHA-256 digest is 64 hexadecimal characters.');
        }

        return new self($hex);
    }

    /**
     * Constant-time comparison.
     */
    public function equals(self|string $other): bool
    {
        return hash_equals($this->hex, $other instanceof self ? $other->hex : strtolower($other));
    }

    /**
     * The 32 raw bytes.
     */
    public function binary(): string
    {
        return (string) hex2bin($this->hex);
    }

    public function __toString(): string
    {
        return $this->hex;
    }
}
